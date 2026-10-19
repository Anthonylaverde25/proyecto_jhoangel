<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\ItemDocumentType;
use App\Core\Exceptions\ItemDocumentException;
use App\Core\ValueObjects\TransferCounterpart;

/**
 * A stock operation (DS14). Append-only: a mistake is undone with a REVERSAL, never edited. A
 * purchase receipt or a return comes from a provider document and only voiding that document
 * reverses it (DS20). A transfer is two operations: the TRANSFER_IN points to its TRANSFER_OUT (DD7).
 */
final class ItemDocumentEntity
{
    /** @param list<ItemDocumentLineEntity> $lines */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private ?int $number,
        private readonly ItemDocumentType $type,
        private readonly int $warehouseId,
        private readonly string $occurredAt,
        private readonly array $lines,
        private readonly ?int $fiscalDocumentId = null,
        private readonly ?int $batchId = null,
        private readonly ?int $headsTreated = null,
        private readonly ?int $reversesDocumentId = null,
        private readonly ?string $reason = null,
        private readonly ?string $notes = null,
        private readonly ?string $idempotencyKey = null,
        private readonly ?int $createdBy = null,
        private readonly ?string $warehouseName = null,
        private readonly ?string $fiscalDocumentLabel = null,
        private readonly ?string $batchName = null,
        private readonly ?int $reversesNumber = null,
        private readonly ?int $reversedById = null,
        private readonly ?int $reversedByNumber = null,
        private readonly ?string $createdByName = null,
        private readonly ?string $createdAt = null,
        private readonly ?int $pairedDocumentId = null,
        private readonly ?string $warehouseCode = null,
        private readonly ?TransferCounterpart $counterpart = null,
    ) {
        if ($lines === []) {
            throw ItemDocumentException::on('lines', 'La operación no tiene renglones.');
        }
        // E2: a purchase receipt and a return always come from a provider document.
        if ($type->comesFromFiscalDocument() && $fiscalDocumentId === null) {
            throw ItemDocumentException::on('domain', "{$type->label()} necesita el comprobante del proveedor.");
        }
        // E3, guarded here as well as by the MySQL CHECK, because tests run on SQLite.
        if ($batchId !== null && !in_array($type, [ItemDocumentType::CONSUMPTION, ItemDocumentType::REVERSAL], true)) {
            throw ItemDocumentException::on('batch_id', 'El lote va sólo en los consumos.');
        }
        if (($batchId === null) !== ($headsTreated === null) || ($headsTreated !== null && $headsTreated <= 0)) {
            throw ItemDocumentException::on('heads_treated', 'Indicá cuántas cabezas recibieron el consumo.');
        }
        if (($type === ItemDocumentType::TRANSFER_IN) !== ($pairedDocumentId !== null)) {
            throw ItemDocumentException::on('domain', 'La entrada de una transferencia apunta a su salida, y sólo ella.');
        }
        // E5 for reversals; the count's reason depends on its difference and is checked by the ledger.
        if ($type === ItemDocumentType::REVERSAL && ($reversesDocumentId === null || trim((string) $reason) === '')) {
            throw ItemDocumentException::on('reason', 'Indicá el motivo de la anulación.');
        }
    }

    public function assignNumber(int $number): void
    {
        $this->number = $number;
    }

    /** E4: what this operation's reversal would be refused for, or null when it can be reversed. */
    public function reversalRefusal(): ?string
    {
        if ($this->type === ItemDocumentType::REVERSAL) {
            return 'Una anulación no se puede anular.';
        }
        if ($this->reversedById !== null) {
            return "El movimiento N.º {$this->number} ya está anulado (anulación N.º {$this->reversedByNumber}).";
        }
        if ($this->type->comesFromFiscalDocument()) {
            return "Este movimiento viene de {$this->fiscalDocumentLabel}: anulá el comprobante.";
        }

        return null;
    }

    public function getId(): ?int { return $this->id; }
    public function getCompanyId(): int { return $this->companyId; }
    public function getNumber(): ?int { return $this->number; }
    public function getType(): ItemDocumentType { return $this->type; }
    public function getWarehouseId(): int { return $this->warehouseId; }
    public function getOccurredAt(): string { return $this->occurredAt; }
    /** @return list<ItemDocumentLineEntity> */
    public function getLines(): array { return $this->lines; }
    public function getFiscalDocumentId(): ?int { return $this->fiscalDocumentId; }
    public function getBatchId(): ?int { return $this->batchId; }
    public function getHeadsTreated(): ?int { return $this->headsTreated; }
    public function getReversesDocumentId(): ?int { return $this->reversesDocumentId; }
    public function getReason(): ?string { return $this->reason; }
    public function getNotes(): ?string { return $this->notes; }
    public function getIdempotencyKey(): ?string { return $this->idempotencyKey; }
    public function getCreatedBy(): ?int { return $this->createdBy; }
    public function getWarehouseName(): ?string { return $this->warehouseName; }
    public function getFiscalDocumentLabel(): ?string { return $this->fiscalDocumentLabel; }
    public function getBatchName(): ?string { return $this->batchName; }
    public function getReversesNumber(): ?int { return $this->reversesNumber; }
    public function getReversedById(): ?int { return $this->reversedById; }
    public function getReversedByNumber(): ?int { return $this->reversedByNumber; }
    public function getCreatedByName(): ?string { return $this->createdByName; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getPairedDocumentId(): ?int { return $this->pairedDocumentId; }
    public function getWarehouseCode(): ?string { return $this->warehouseCode; }
    public function getCounterpart(): ?TransferCounterpart { return $this->counterpart; }

    public function totalCost(): string
    {
        return array_reduce($this->lines, fn (string $sum, ItemDocumentLineEntity $l) => bcadd($sum, $l->getTotalCost(), 4), '0');
    }
}
