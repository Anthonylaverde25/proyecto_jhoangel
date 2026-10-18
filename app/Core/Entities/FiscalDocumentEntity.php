<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\FiscalDocumentKind;
use App\Core\Enums\FiscalDocumentStatus;
use App\Core\Exceptions\FiscalDocumentException;

/**
 * A provider document as it is on paper (DS14): invoice, credit note, ticket or delivery note. Immutable once
 * registered; voiding it is the only later change (DS20). Amounts are always positive: the type's
 * sign says whether it is a cost or a credit.
 */
final class FiscalDocumentEntity
{
    /**
     * @param list<FiscalDocumentLineEntity> $lines
     * @param list<FiscalDocumentTaxEntity> $documentTaxes
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $providerId,
        private readonly FiscalDocumentTypeEntity $type,
        private readonly ?int $pointOfSale,
        private readonly ?int $number,
        private readonly ?string $reference,
        private readonly string $issuedAt,
        private readonly ?int $relatedFiscalDocumentId,
        private readonly ?string $linesAmount,
        private readonly ?string $lineTaxesAmount,
        private readonly ?string $documentTaxesAmount,
        private readonly ?string $totalAmount,
        private readonly ?string $declaredTotal,
        private readonly array $lines,
        private readonly array $documentTaxes,
        private FiscalDocumentStatus $status = FiscalDocumentStatus::REGISTERED,
        private ?string $voidReason = null,
        private ?string $voidedAt = null,
        private readonly ?string $notes = null,
        private readonly ?string $idempotencyKey = null,
        private readonly ?int $createdBy = null,
        private readonly ?string $providerName = null,
        private readonly ?string $createdByName = null,
        private readonly ?string $createdAt = null,
        private readonly ?string $relatedLabel = null,
        private readonly ?int $stockOperationId = null,
        private readonly ?int $stockOperationNumber = null,
        /** Delivery notes only (DC14): null on any other document. */
        private readonly ?bool $isValued = null,
        private readonly ?int $invoicedByDocumentId = null,
        private readonly ?string $invoicedByLabel = null,
        /** An invoice converted from delivery notes: array{id, label, issued_at} each. @var list<array<string, mixed>> */
        private readonly array $deliveryNotes = [],
        /** The delivery notes the invoice is converted from, when it is being registered (DC5). @var list<int> */
        private readonly array $deliveryNoteIds = [],
    ) {
        if ($lines === []) {
            throw FiscalDocumentException::on('lines', 'El comprobante no tiene renglones.');
        }
    }

    /** F8: only a registered document is voided, and always with a reason. */
    public function void(string $reason, string $at): void
    {
        if ($this->status === FiscalDocumentStatus::VOIDED) {
            throw FiscalDocumentException::on('domain', "{$this->label()} ya está anulado.");
        }
        if (trim($reason) === '') {
            throw FiscalDocumentException::on('reason', 'Indicá el motivo de la anulación.');
        }
        $this->status = FiscalDocumentStatus::VOIDED;
        $this->voidReason = $reason;
        $this->voidedAt = $at;
    }

    /** "Factura A 0001-00000123", or the ticket's reference. */
    public function label(): string
    {
        return $this->type->name . ' ' . self::formatNumber($this->pointOfSale, $this->number, $this->reference);
    }

    public static function formatNumber(?int $pointOfSale, ?int $number, ?string $reference): string
    {
        if ($pointOfSale === null || $number === null) {
            return $reference ?? 's/n';
        }

        return sprintf('%04d-%08d', $pointOfSale, $number);
    }

    /** Declared total minus computed total, when the operator copied it from the paper (DS19). */
    public function totalDifference(): ?string
    {
        if ($this->declaredTotal === null || $this->totalAmount === null) {
            return null;
        }

        return bcsub($this->declaredTotal, $this->totalAmount, 4);
    }

    public function getId(): ?int { return $this->id; }
    public function getCompanyId(): int { return $this->companyId; }
    public function getProviderId(): int { return $this->providerId; }
    public function getType(): FiscalDocumentTypeEntity { return $this->type; }
    public function getPointOfSale(): ?int { return $this->pointOfSale; }
    public function getNumber(): ?int { return $this->number; }
    public function getReference(): ?string { return $this->reference; }
    public function getIssuedAt(): string { return $this->issuedAt; }
    public function getRelatedFiscalDocumentId(): ?int { return $this->relatedFiscalDocumentId; }
    public function getLinesAmount(): ?string { return $this->linesAmount; }
    public function getLineTaxesAmount(): ?string { return $this->lineTaxesAmount; }
    public function getDocumentTaxesAmount(): ?string { return $this->documentTaxesAmount; }
    public function getTotalAmount(): ?string { return $this->totalAmount; }
    public function isValued(): ?bool { return $this->isValued; }
    public function isDeliveryNote(): bool { return $this->type->kind === FiscalDocumentKind::DELIVERY_NOTE; }
    public function getInvoicedByDocumentId(): ?int { return $this->invoicedByDocumentId; }
    public function getInvoicedByLabel(): ?string { return $this->invoicedByLabel; }
    /** @return list<array<string, mixed>> */
    public function getDeliveryNotes(): array { return $this->deliveryNotes; }
    /** @return list<int> */
    public function getDeliveryNoteIds(): array { return $this->deliveryNoteIds; }
    public function getDeclaredTotal(): ?string { return $this->declaredTotal; }
    /** @return list<FiscalDocumentLineEntity> */
    public function getLines(): array { return $this->lines; }
    /** @return list<FiscalDocumentTaxEntity> */
    public function getDocumentTaxes(): array { return $this->documentTaxes; }
    public function getStatus(): FiscalDocumentStatus { return $this->status; }
    public function isVoided(): bool { return $this->status === FiscalDocumentStatus::VOIDED; }
    public function getVoidReason(): ?string { return $this->voidReason; }
    public function getVoidedAt(): ?string { return $this->voidedAt; }
    public function getNotes(): ?string { return $this->notes; }
    public function getIdempotencyKey(): ?string { return $this->idempotencyKey; }
    public function getCreatedBy(): ?int { return $this->createdBy; }
    public function getProviderName(): ?string { return $this->providerName; }
    public function getCreatedByName(): ?string { return $this->createdByName; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getRelatedLabel(): ?string { return $this->relatedLabel; }
    public function getStockOperationId(): ?int { return $this->stockOperationId; }
    public function getStockOperationNumber(): ?int { return $this->stockOperationNumber; }

    /** @return list<FiscalDocumentLineEntity> lines whose item counts in a warehouse */
    public function stockedLines(): array
    {
        return array_values(array_filter($this->lines, fn (FiscalDocumentLineEntity $line) => $line->tracksStock));
    }
}
