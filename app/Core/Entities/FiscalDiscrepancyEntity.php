<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\DiscrepancyStatus;
use App\Core\Enums\DiscrepancyType;
use App\Core\Exceptions\FiscalDocumentException;

/**
 * A difference between a delivery note and the invoice it went into (DC7). Nothing is corrected in
 * stock: the client settles it with the provider and closes it with a note (DC8).
 */
final class FiscalDiscrepancyEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $invoiceDocumentId,
        private readonly ?int $deliveryNoteId,
        private readonly ?int $invoiceLineId,
        private readonly ?int $deliveryNoteLineId,
        private readonly ?int $itemId,
        private readonly DiscrepancyType $type,
        private readonly ?string $expectedValue,
        private readonly ?string $actualValue,
        private readonly string $detail,
        private DiscrepancyStatus $status = DiscrepancyStatus::OPEN,
        private ?string $resolution = null,
        private ?int $resolvedByUserId = null,
        private ?string $resolvedAt = null,
        private readonly ?int $raisedByUserId = null,
        private readonly ?string $createdAt = null,
        private readonly ?string $invoiceLabel = null,
        private readonly ?string $deliveryNoteLabel = null,
        private readonly ?string $itemName = null,
        private readonly ?string $resolvedByName = null,
    ) {
    }

    public function resolve(string $resolution, int $userId, string $at): void
    {
        if ($this->status !== DiscrepancyStatus::OPEN) {
            throw FiscalDocumentException::on('domain', $this->status === DiscrepancyStatus::CANCELLED
                ? 'La novedad se canceló al anular la factura: no hay nada que resolver.'
                : 'La novedad ya está resuelta.');
        }
        if (trim($resolution) === '') {
            throw FiscalDocumentException::on('resolution', 'Contá cómo se resolvió con el proveedor.');
        }

        $this->status = DiscrepancyStatus::RESOLVED;
        $this->resolution = trim($resolution);
        $this->resolvedByUserId = $userId;
        $this->resolvedAt = $at;
    }

    public function getId(): ?int { return $this->id; }
    public function getCompanyId(): int { return $this->companyId; }
    public function getInvoiceDocumentId(): int { return $this->invoiceDocumentId; }
    public function getDeliveryNoteId(): ?int { return $this->deliveryNoteId; }
    public function getInvoiceLineId(): ?int { return $this->invoiceLineId; }
    public function getDeliveryNoteLineId(): ?int { return $this->deliveryNoteLineId; }
    public function getItemId(): ?int { return $this->itemId; }
    public function getType(): DiscrepancyType { return $this->type; }
    public function getExpectedValue(): ?string { return $this->expectedValue; }
    public function getActualValue(): ?string { return $this->actualValue; }
    public function getDetail(): string { return $this->detail; }
    public function getStatus(): DiscrepancyStatus { return $this->status; }
    public function getResolution(): ?string { return $this->resolution; }
    public function getResolvedByUserId(): ?int { return $this->resolvedByUserId; }
    public function getResolvedAt(): ?string { return $this->resolvedAt; }
    public function getRaisedByUserId(): ?int { return $this->raisedByUserId; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getInvoiceLabel(): ?string { return $this->invoiceLabel; }
    public function getDeliveryNoteLabel(): ?string { return $this->deliveryNoteLabel; }
    public function getItemName(): ?string { return $this->itemName; }
    public function getResolvedByName(): ?string { return $this->resolvedByName; }
}
