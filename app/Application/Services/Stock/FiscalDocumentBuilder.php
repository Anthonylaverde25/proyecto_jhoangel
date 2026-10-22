<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Application\DTOs\Stock\FiscalDocumentDTO;
use App\Application\DTOs\Stock\FiscalLineDTO;
use App\Application\DTOs\Stock\TaxInputDTO;
use App\Core\Entities\FiscalDocumentEntity;
use App\Core\Entities\FiscalDocumentLineEntity;
use App\Core\Entities\FiscalDocumentTaxEntity;
use App\Core\Entities\FiscalDocumentTypeEntity;
use App\Core\Entities\TaxEntity;
use App\Core\Enums\FiscalDocumentKind;
use App\Core\Enums\TaxScope;
use App\Core\Exceptions\FiscalDocumentException;
use App\Core\Interfaces\IFiscalCatalogRepository;
use App\Core\Interfaces\IFiscalDocumentRepository;
use App\Core\Interfaces\IProviderRepository;
use App\Core\Services\FiscalDocumentCalculator;
use App\Core\ValueObjects\Decimal;

/**
 * Checks a provider document as typed from the paper and computes its amounts, taxes and costs
 * (plan 4.2 and 4.5). Collects every broken rule before answering, by field.
 */
final class FiscalDocumentBuilder
{
    /** @var array<int, TaxEntity> */
    private array $taxes = [];

    public function __construct(
        private readonly IFiscalCatalogRepository $catalog,
        private readonly IFiscalDocumentRepository $documents,
        private readonly IProviderRepository $providers,
        private readonly ItemLineResolver $lines,
        private readonly BatchConsumptionGuard $batches,
        private readonly FiscalDocumentCalculator $calculator,
        private readonly DeliveryNoteRules $deliveryNotes,
    ) {
    }

    public function build(FiscalDocumentDTO $dto, int $companyId, string $today): FiscalDocumentEntity
    {
        $errors = new FieldErrorBag();
        $type = $this->catalog->findDocumentType($dto->typeId);
        if ($type === null || !$type->isActive) {
            // F2: without the type nothing else can be judged.
            throw FiscalDocumentException::on('fiscal_document_type_id', $type === null
                ? 'El tipo de comprobante no existe.'
                : "{$type->name}: todavía no se carga.");
        }
        // Without a date the catalog brings every rate; the line checks the one in force (L5).
        foreach ($this->catalog->taxes() as $tax) {
            $this->taxes[$tax->id] = $tax;
        }

        $isDeliveryNote = $type->kind === FiscalDocumentKind::DELIVERY_NOTE;
        if ($isDeliveryNote) {
            $this->deliveryNotes->checkDeliveryNoteHeader($dto, $errors);
        }
        // RF1–RF2: the delivery notes an invoice is converted from, locked until the transaction ends.
        $sources = $isDeliveryNote ? [] : $this->deliveryNotes->lockSources($dto, $type, $companyId, $errors);

        $providerName = $this->checkProvider($dto, $companyId, $errors);
        $this->checkNumber($dto, $type, $companyId, $providerName, $errors);
        if ($dto->issuedAt > $today) {
            $errors->add('issued_at', 'La fecha no puede ser futura.');
        }
        $this->checkRelated($dto, $type, $companyId, $errors);
        if ($dto->lines === []) {
            $errors->add('lines', 'El comprobante no tiene renglones.');
        }

        $lines = [];
        foreach ($dto->lines as $index => $line) {
            $built = $this->line($index, $line, $type, $dto->issuedAt, $companyId, $errors, $isDeliveryNote ? $dto->isValued : null);
            if ($built !== null) {
                $lines[] = $built;
            }
        }
        $this->deliveryNotes->checkConvertedLines($dto, $sources, $errors);
        $documentTaxes = $isDeliveryNote ? [] : $this->documentTaxes($dto->documentTaxes, $type, $errors);

        $errors->throwIfAny(FiscalDocumentException::class);

        $linesAmount = Decimal::zero();
        $lineTaxes = Decimal::zero();
        foreach ($lines as $line) {
            if ($line->lineAmount === null) {
                continue; // an unvalued delivery note has no amounts to add
            }
            $linesAmount = $linesAmount->add(Decimal::of($line->lineAmount));
            foreach ($line->taxes as $tax) {
                $lineTaxes = $lineTaxes->add(Decimal::of($tax->amount));
            }
        }
        $docTaxes = array_reduce($documentTaxes, fn (Decimal $sum, FiscalDocumentTaxEntity $t) => $sum->add(Decimal::of($t->amount)), Decimal::zero());
        // An unvalued delivery note has no amounts at all: NULL, never a fabricated 0 (H2).
        $unpriced = $isDeliveryNote && $dto->isValued === false;

        return new FiscalDocumentEntity(
            null,
            $companyId,
            $dto->providerId,
            $type,
            $type->kind->requiresNumber() ? $dto->pointOfSale : null,
            $type->kind->requiresNumber() ? $dto->number : null,
            $type->kind->requiresNumber() ? null : $dto->reference,
            $dto->issuedAt,
            $dto->relatedFiscalDocumentId,
            $unpriced ? null : $linesAmount->toScale(4),
            $unpriced ? null : $lineTaxes->toScale(4),
            $unpriced ? null : $docTaxes->toScale(4),
            $unpriced ? null : $linesAmount->add($lineTaxes)->add($docTaxes)->toScale(4),
            $dto->declaredTotal === null ? null : Decimal::of($dto->declaredTotal)->toScale(4),
            $lines,
            $documentTaxes,
            notes: $dto->notes,
            idempotencyKey: $dto->idempotencyKey,
            createdBy: $dto->createdBy,
            isValued: $isDeliveryNote ? $dto->isValued : null,
            deliveryNoteIds: $isDeliveryNote ? [] : array_values(array_unique($dto->deliveryNoteIds)),
        );
    }

    /** F3: a provider of the company that sells articles or services. */
    private function checkProvider(FiscalDocumentDTO $dto, int $companyId, FieldErrorBag $errors): ?string
    {
        $provider = $this->providers->findById($dto->providerId);
        if ($provider === null || $provider->getCompanyId() !== $companyId) {
            $errors->add('provider_id', 'El proveedor no existe en esta empresa.');

            return null;
        }
        if (!$provider->suppliesItems()) {
            $errors->add('provider_id', "{$provider->getName()} no está marcado como proveedor de artículos o servicios.");
        }

        return $provider->getName();
    }

    /** F4 and F5: point of sale and number, unless it is a ticket, and not loaded twice. */
    private function checkNumber(FiscalDocumentDTO $dto, FiscalDocumentTypeEntity $type, int $companyId, ?string $providerName, FieldErrorBag $errors): void
    {
        if (!$type->kind->requiresNumber()) {
            return;
        }
        if ($dto->pointOfSale === null || $dto->pointOfSale <= 0) {
            $errors->add('point_of_sale', 'Falta el punto de venta.');
        }
        if ($dto->number === null || $dto->number <= 0) {
            $errors->add('number', 'Falta el número del comprobante.');
        }
        if ($errors->has('point_of_sale') || $errors->has('number') || $providerName === null) {
            return;
        }

        $duplicate = $this->documents->findRegisteredDuplicate($companyId, $dto->providerId, $type->id, (int) $dto->pointOfSale, (int) $dto->number);
        if ($duplicate !== null) {
            $date = date('d/m/Y', (int) strtotime($duplicate->getIssuedAt()));
            $errors->add('number', "La {$duplicate->label()} de {$providerName} ya está cargada (emitida el {$date}).");
        }
    }

    /** F7: a credit note may name the invoice it credits, a registered one of the same provider. */
    private function checkRelated(FiscalDocumentDTO $dto, FiscalDocumentTypeEntity $type, int $companyId, FieldErrorBag $errors): void
    {
        if ($dto->relatedFiscalDocumentId === null) {
            return;
        }
        if ($type->kind !== FiscalDocumentKind::CREDIT_NOTE) {
            $errors->add('related_fiscal_document_id', 'Sólo una nota de crédito indica la factura que acredita.');

            return;
        }
        $related = $this->documents->findById($dto->relatedFiscalDocumentId, $companyId);
        if ($related === null || $related->getType()->kind !== FiscalDocumentKind::INVOICE || $related->isVoided()) {
            $errors->add('related_fiscal_document_id', 'La factura elegida no está registrada.');
        } elseif ($related->getProviderId() !== $dto->providerId) {
            $errors->add('related_fiscal_document_id', 'La factura elegida es de otro proveedor.');
        }
    }

    private function line(int $index, FiscalLineDTO $dto, FiscalDocumentTypeEntity $type, string $issuedAt, int $companyId, FieldErrorBag $errors, ?bool $deliveryNoteIsValued): ?FiscalDocumentLineEntity
    {
        $prefix = "lines.{$index}";
        $resolved = $this->lines->resolve($prefix, $companyId, $dto->itemId, $dto->inputQuantity, $dto->inputUnitId, $dto->inputPresentationId, $errors, false);

        if ($type->kind === FiscalDocumentKind::DELIVERY_NOTE) {
            return $this->deliveryNoteLine($index, $dto, $resolved, $deliveryNoteIsValued, $errors);
        }
        if ($dto->unitPrice === null) {
            $errors->add("{$prefix}.unit_price", 'Falta el precio.');

            return null;
        }

        // L4: the batch goes on services and non-stock articles; stocked ones are charged on consumption.
        if ($dto->batchId !== null) {
            if ($resolved !== null && $resolved->item->tracksStock()) {
                $errors->add("{$prefix}.batch_id", 'El lote va en los servicios y en los artículos sin stock: lo que va al almacén se imputa al consumirlo.');
            } else {
                $this->batches->check($dto->batchId, $dto->headsTreated, $companyId, $prefix, $errors);
            }
        }

        $amount = $this->calculator->lineAmount(Decimal::of($dto->inputQuantity), Decimal::of($dto->unitPrice));
        $taxes = $this->lineTaxes($prefix, $dto->taxes, $type, $issuedAt, $amount, $errors);
        if ($resolved === null || $taxes === null) {
            return null;
        }

        $nonRecoverable = Decimal::zero();
        foreach ($taxes as $tax) {
            if (!$tax->isRecoverable) {
                $nonRecoverable = $nonRecoverable->add(Decimal::of($tax->amount));
            }
        }
        $cost = $amount->add($nonRecoverable);

        return new FiscalDocumentLineEntity(
            $index + 1,
            $dto->itemId,
            $resolved->inputQuantity->toScale(4),
            $resolved->inputUnitId,
            $resolved->inputPresentationId,
            $resolved->quantity->toScale(4),
            Decimal::of($dto->unitPrice)->toScale(6),
            $amount->toScale(4),
            $nonRecoverable->toScale(4),
            $cost->toScale(4),
            $this->calculator->unitCost($cost, $resolved->quantity)->toScale(6),
            $dto->batchId,
            $dto->batchId === null ? null : $dto->headsTreated,
            $taxes,
            $resolved->item->tracksStock(),
            deliveryNoteLineId: $dto->deliveryNoteLineId,
            sourceDescription: $dto->sourceDescription,
        );
    }

    /** RM3–RM6: a stocked article, without taxes or batch, priced or not as the header says. */
    private function deliveryNoteLine(int $index, FiscalLineDTO $dto, ?ResolvedLine $resolved, ?bool $isValued, FieldErrorBag $errors): ?FiscalDocumentLineEntity
    {
        if ($resolved === null) {
            return null;
        }
        $ok = $this->deliveryNotes->checkDeliveryNoteLine("lines.{$index}", $dto, $resolved->item->tracksStock(), (string) $resolved->item->getName(), $isValued, $errors);
        if (!$ok || $isValued === null) {
            return null;
        }

        $amount = $dto->unitPrice === null ? null : $this->calculator->lineAmount(Decimal::of($dto->inputQuantity), Decimal::of($dto->unitPrice));

        return new FiscalDocumentLineEntity(
            $index + 1,
            $dto->itemId,
            $resolved->inputQuantity->toScale(4),
            $resolved->inputUnitId,
            $resolved->inputPresentationId,
            $resolved->quantity->toScale(4),
            $dto->unitPrice === null ? null : Decimal::of($dto->unitPrice)->toScale(6),
            $amount?->toScale(4),
            '0.0000',
            $amount?->toScale(4),
            $amount === null ? null : $this->calculator->unitCost($amount, $resolved->quantity)->toScale(6),
            null,
            null,
            [],
            true,
            sourceDescription: $dto->sourceDescription,
        );
    }

    /**
     * L5–L7: letter A discriminates exactly one VAT per line plus any other line tax; B, C and tickets
     * discriminate nothing (DS18).
     *
     * @param list<TaxInputDTO> $inputs
     * @return list<FiscalDocumentTaxEntity>|null
     */
    private function lineTaxes(string $prefix, array $inputs, FiscalDocumentTypeEntity $type, string $issuedAt, Decimal $amount, FieldErrorBag $errors): ?array
    {
        if (!$type->discriminatesVat) {
            if ($inputs !== []) {
                $errors->add("{$prefix}.taxes", "Una {$type->name} no discrimina impuestos: el precio ya los incluye.");

                return null;
            }

            return [];
        }

        $taxes = [];
        $seen = [];
        $failed = false;
        foreach ($inputs as $j => $input) {
            $field = "{$prefix}.taxes.{$j}";
            $tax = $this->taxes[$input->taxId] ?? null;
            if ($tax === null || !$tax->isActive || $tax->scope !== TaxScope::LINE) {
                $errors->add("{$field}.tax_id", $tax === null ? 'El impuesto no existe.' : "{$tax->name} va en los impuestos del comprobante, no en el renglón.");
                $failed = true;
                continue;
            }
            if (isset($seen[$tax->id])) {
                $errors->add("{$field}.tax_id", "{$tax->name} está dos veces en el renglón.");
                $failed = true;
                continue;
            }
            $seen[$tax->id] = true;

            $built = $tax->isVat()
                ? $this->vat($field, $input, $tax, $issuedAt, $amount, $errors)
                : $this->declaredTax($field, $input, $tax, $errors);
            if ($built === null) {
                $failed = true;
                continue;
            }
            $taxes[] = $built;
        }

        $vatCount = count(array_filter($taxes, fn (FiscalDocumentTaxEntity $t) => $t->taxCode === TaxEntity::VAT));
        if (!$failed && $vatCount === 0) {
            $errors->add("{$prefix}.taxes", 'Falta la alícuota de IVA.');
            $failed = true;
        }

        return $failed ? null : $taxes;
    }

    private function vat(string $field, TaxInputDTO $input, TaxEntity $tax, string $issuedAt, Decimal $amount, FieldErrorBag $errors): ?FiscalDocumentTaxEntity
    {
        $rate = null;
        foreach ($tax->rates as $candidate) {
            if ($candidate->id === $input->taxRateId) {
                $rate = $candidate;
            }
        }
        if ($rate === null || !$rate->isValidOn($issuedAt)) {
            $errors->add("{$field}.tax_rate_id", $rate === null ? 'Elegí la alícuota de IVA.' : "La alícuota {$rate->name} no estaba vigente el {$issuedAt}.");

            return null;
        }

        return new FiscalDocumentTaxEntity(
            $tax->id,
            $tax->code,
            $tax->name,
            $rate->id,
            $rate->name,
            $rate->rate,
            $amount->toScale(4),
            $this->calculator->percentage($amount, Decimal::of($rate->rate))->toScale(4),
            $tax->isRecoverable,
        );
    }

    /** A tax whose amount is printed on the paper (fuel tax, internal taxes, perceptions). */
    private function declaredTax(string $field, TaxInputDTO $input, TaxEntity $tax, FieldErrorBag $errors): ?FiscalDocumentTaxEntity
    {
        if ($input->amount === null || !Decimal::of($input->amount)->isPositive()) {
            $errors->add("{$field}.amount", "Indicá el importe de {$tax->name}.");

            return null;
        }

        return new FiscalDocumentTaxEntity(
            $tax->id,
            $tax->code,
            $tax->name,
            null,
            null,
            $input->rate === null ? null : Decimal::of($input->rate)->toScale(4),
            $input->taxableBase === null ? null : Decimal::of($input->taxableBase)->toScale(4),
            Decimal::of($input->amount)->toScale(4),
            $tax->isRecoverable,
        );
    }

    /**
     * T1–T3: perceptions and other whole-document taxes, only on letter A.
     *
     * @param list<TaxInputDTO> $inputs
     * @return list<FiscalDocumentTaxEntity>
     */
    private function documentTaxes(array $inputs, FiscalDocumentTypeEntity $type, FieldErrorBag $errors): array
    {
        if ($inputs !== [] && !$type->discriminatesVat) {
            $errors->add('document_taxes', "Una {$type->name} no lleva impuestos discriminados.");

            return [];
        }

        $taxes = [];
        $seen = [];
        foreach ($inputs as $j => $input) {
            $field = "document_taxes.{$j}";
            $tax = $this->taxes[$input->taxId] ?? null;
            if ($tax === null || !$tax->isActive || $tax->scope !== TaxScope::DOCUMENT) {
                $errors->add("{$field}.tax_id", $tax === null ? 'El impuesto no existe.' : "{$tax->name} va en el renglón, no en los impuestos del comprobante.");
                continue;
            }
            if (isset($seen[$tax->id])) {
                $errors->add("{$field}.tax_id", "{$tax->name} está dos veces.");
                continue;
            }
            $seen[$tax->id] = true;
            $built = $this->declaredTax($field, $input, $tax, $errors);
            if ($built !== null) {
                $taxes[] = $built;
            }
        }

        return $taxes;
    }
}
