<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Application\DTOs\Stock\FiscalDocumentDTO;
use App\Application\DTOs\Stock\FiscalLineDTO;
use App\Core\Entities\FiscalDocumentEntity;
use App\Core\Entities\FiscalDocumentLineEntity;
use App\Core\Entities\FiscalDocumentTypeEntity;
use App\Core\Enums\FiscalDocumentKind;
use App\Core\Interfaces\IFiscalDocumentRepository;

/**
 * Delivery C, plan part 5 §5.3: what a delivery note may carry (RM2–RM6) and what an invoice converted
 * from delivery notes must keep from them (RF1–RF4). Collects the broken rules in the builder's bag.
 */
final class DeliveryNoteRules
{
    public function __construct(
        private readonly IFiscalDocumentRepository $documents,
    ) {
    }

    /** RM2, RM5 and the header half of RM4: a delivery note says whether it is priced, and carries no taxes. */
    public function checkDeliveryNoteHeader(FiscalDocumentDTO $dto, FieldErrorBag $errors): void
    {
        if ($dto->isValued === null) {
            $errors->add('is_valued', 'Indicá si el remito trae precios.');
        }
        if ($dto->documentTaxes !== [] || $dto->declaredTotal !== null) {
            $errors->add('document_taxes', 'Los impuestos y el total van en la factura, no en el remito.');
        }
        if ($dto->deliveryNoteIds !== []) {
            $errors->add('delivery_note_ids', 'Un remito no se convierte en otro remito.');
        }
    }

    /** RM3–RM6 on one line. False when the line cannot be built. */
    public function checkDeliveryNoteLine(string $prefix, FiscalLineDTO $line, bool $tracksStock, string $itemName, ?bool $isValued, FieldErrorBag $errors): bool
    {
        $ok = true;
        if (!$tracksStock) {
            $errors->add("{$prefix}.item_id", "El remito sólo lleva artículos con stock: {$itemName} no lo es.");
            $ok = false;
        }
        if ($line->taxes !== []) {
            $errors->add("{$prefix}.taxes", 'Los impuestos van en la factura, no en el remito.');
            $ok = false;
        }
        if ($line->batchId !== null) {
            $errors->add("{$prefix}.batch_id", 'El remito ingresa al almacén: el lote se indica al consumir.');
            $ok = false;
        }
        if ($isValued === true && $line->unitPrice === null) {
            $errors->add("{$prefix}.unit_price", 'El remito es valorizado: falta el precio.');
            $ok = false;
        }
        if ($isValued === false && $line->unitPrice !== null) {
            $errors->add("{$prefix}.unit_price", 'El remito no trae precios: quitá el precio.');
            $ok = false;
        }

        return $ok;
    }

    /**
     * RF1–RF2: the delivery notes an invoice is converted from, locked. Keyed by delivery-note line id,
     * with the note each line belongs to; empty when something is wrong (the bag says what).
     *
     * @return array<int, array{note: FiscalDocumentEntity, line: FiscalDocumentLineEntity}>
     */
    public function lockSources(FiscalDocumentDTO $dto, FiscalDocumentTypeEntity $type, int $companyId, FieldErrorBag $errors): array
    {
        if ($dto->deliveryNoteIds === []) {
            foreach ($dto->lines as $index => $line) {
                if ($line->deliveryNoteLineId !== null) {
                    $errors->add("lines.{$index}.delivery_note_line_id", 'El renglón viene de un remito, pero no se eligió ningún remito.');
                }
            }

            return [];
        }

        if (!$type->kind->isConvertible()) {
            $errors->add('delivery_note_ids', 'Un remito sólo se convierte en una factura.');

            return [];
        }

        $notes = [];
        foreach ($this->documents->lockDeliveryNotes(array_values(array_unique($dto->deliveryNoteIds)), $companyId) as $note) {
            $notes[(int) $note->getId()] = $note;
        }
        $sources = [];
        foreach ($dto->deliveryNoteIds as $index => $id) {
            $note = $notes[$id] ?? null;
            if ($note === null || $note->getType()->kind !== FiscalDocumentKind::DELIVERY_NOTE) {
                $errors->add("delivery_note_ids.{$index}", 'El remito no existe en esta empresa.');
                continue;
            }
            if ($note->isVoided()) {
                $errors->add("delivery_note_ids.{$index}", "El {$note->label()} está anulado.");
                continue;
            }
            if ($note->getInvoicedByDocumentId() !== null) {
                $errors->add("delivery_note_ids.{$index}", "El {$note->label()} ya está en la {$note->getInvoicedByLabel()}.");
                continue;
            }
            if ($note->getProviderId() !== $dto->providerId) {
                $errors->add("delivery_note_ids.{$index}", "El {$note->label()} es de {$note->getProviderName()}: una factura junta remitos de su mismo proveedor.");
                continue;
            }
            foreach ($note->getLines() as $line) {
                $sources[(int) $line->id] = ['note' => $note, 'line' => $line];
            }
        }

        return $sources;
    }

    /**
     * RF3–RF4: a converted line comes from one of the chosen notes, only once, and keeps its item.
     *
     * @param array<int, array{note: FiscalDocumentEntity, line: FiscalDocumentLineEntity}> $sources
     */
    public function checkConvertedLines(FiscalDocumentDTO $dto, array $sources, FieldErrorBag $errors): void
    {
        if ($sources === []) {
            return;
        }

        $used = [];
        foreach ($dto->lines as $index => $line) {
            $id = $line->deliveryNoteLineId;
            if ($id === null) {
                continue;
            }
            $source = $sources[$id] ?? null;
            if ($source === null) {
                $errors->add("lines.{$index}.delivery_note_line_id", 'El renglón no es de los remitos elegidos.');
                continue;
            }
            if (isset($used[$id])) {
                $errors->add("lines.{$index}.delivery_note_line_id", "El renglón {$source['line']->position} del {$source['note']->label()} está dos veces.");
                continue;
            }
            $used[$id] = true;
            if ($source['line']->itemId !== $line->itemId) {
                $errors->add("lines.{$index}.item_id", "El renglón viene del {$source['note']->label()} con {$source['line']->itemName}: no se puede cambiar el ítem.");
            }
        }
    }
}
