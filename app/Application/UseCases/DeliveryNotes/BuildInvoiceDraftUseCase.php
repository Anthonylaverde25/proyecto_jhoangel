<?php

declare(strict_types=1);

namespace App\Application\UseCases\DeliveryNotes;

use App\Core\Entities\FiscalDocumentEntity;
use App\Core\Entities\FiscalDocumentLineEntity;
use App\Core\Enums\FiscalDocumentKind;
use App\Core\Exceptions\FiscalDocumentException;
use App\Core\Interfaces\IFiscalDocumentRepository;

/**
 * "Convertir a factura" (DC5): what the invoice inherits from its delivery notes — provider, items,
 * presentations, quantities and, when the notes are priced, prices. Saves nothing; the invoice is what
 * the operator then copies from the paper.
 */
final class BuildInvoiceDraftUseCase
{
    public function __construct(
        private readonly IFiscalDocumentRepository $documents,
    ) {
    }

    /**
     * @param list<int> $ids
     * @return array<string, mixed>
     */
    public function __invoke(array $ids, int $companyId): array
    {
        $notes = [];
        foreach (array_values(array_unique($ids)) as $index => $id) {
            $note = $this->documents->findById($id, $companyId);
            if ($note === null || $note->getType()->kind !== FiscalDocumentKind::DELIVERY_NOTE) {
                throw FiscalDocumentException::on("ids.{$index}", 'El remito no existe en esta empresa.');
            }
            if ($note->isVoided()) {
                throw FiscalDocumentException::on("ids.{$index}", "El {$note->label()} está anulado.");
            }
            if ($note->getInvoicedByDocumentId() !== null) {
                throw FiscalDocumentException::on("ids.{$index}", "El {$note->label()} ya está en la {$note->getInvoicedByLabel()}.");
            }
            $notes[] = $note;
        }

        if ($notes === []) {
            throw FiscalDocumentException::on('ids', 'Marcá al menos un remito.');
        }

        $providers = array_unique(array_map(fn (FiscalDocumentEntity $n) => $n->getProviderName(), $notes));
        if (count($providers) > 1) {
            throw FiscalDocumentException::on('ids', 'Un remito se convierte con otros del mismo proveedor: marcaste ' . implode(' y ', $providers) . '.');
        }

        return [
            'provider' => ['id' => $notes[0]->getProviderId(), 'name' => $notes[0]->getProviderName()],
            'delivery_notes' => array_map(fn (FiscalDocumentEntity $note) => [
                'id' => $note->getId(),
                'label' => $note->label(),
                'issued_at' => $note->getIssuedAt(),
                'is_valued' => (bool) $note->isValued(),
                'lines' => array_map(fn (FiscalDocumentLineEntity $line) => [
                    'delivery_note_line_id' => $line->id,
                    'position' => $line->position,
                    'item' => ['id' => $line->itemId, 'name' => $line->itemName, 'unit_symbol' => $line->itemUnitSymbol],
                    'input_quantity' => $line->inputQuantity,
                    'input_unit_id' => $line->inputUnitId,
                    'input_presentation_id' => $line->inputPresentationId,
                    'input_label' => $line->inputLabel,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unitPrice,
                ], $note->getLines()),
            ], $notes),
        ];
    }
}
