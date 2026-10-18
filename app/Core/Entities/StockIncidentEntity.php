<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\StockIncidentStatus;
use App\Core\Enums\StockIncidentType;
use App\Core\Exceptions\ItemDocumentException;

/**
 * A non-blocking stock notice (D5, D19). At most one open per warehouse, item and type: a repeated
 * event refreshes it instead of opening another.
 */
final class StockIncidentEntity
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $warehouseId,
        private readonly int $itemId,
        private readonly StockIncidentType $type,
        private ?int $stockMovementId,
        private string $detail,
        private array $metadata,
        private StockIncidentStatus $status = StockIncidentStatus::OPEN,
        private ?string $resolution = null,
        private ?int $resolvedBy = null,
        private ?string $resolvedAt = null,
        private readonly ?int $raisedBy = null,
        private readonly ?string $createdAt = null,
        private readonly ?string $itemName = null,
        private readonly ?string $warehouseName = null,
        private readonly ?string $resolvedByName = null,
    ) {
    }

    /** @param array<string, mixed> $metadata */
    public function refresh(?int $stockMovementId, string $detail, array $metadata): void
    {
        $this->stockMovementId = $stockMovementId ?? $this->stockMovementId;
        $this->detail = $detail;
        $this->metadata = $metadata;
    }

    /** N1: only an open notice is resolved, and by hand only with an explanation. */
    public function resolve(string $resolution, ?int $userId, string $at): void
    {
        if ($this->status !== StockIncidentStatus::OPEN) {
            throw ItemDocumentException::on('domain', 'La novedad ya está resuelta.');
        }
        if (trim($resolution) === '') {
            throw ItemDocumentException::on('resolution', 'Contá cómo se resolvió.');
        }
        $this->status = StockIncidentStatus::RESOLVED;
        $this->resolution = $resolution;
        $this->resolvedBy = $userId;
        $this->resolvedAt = $at;
    }

    public function getId(): ?int { return $this->id; }
    public function getCompanyId(): int { return $this->companyId; }
    public function getWarehouseId(): int { return $this->warehouseId; }
    public function getItemId(): int { return $this->itemId; }
    public function getType(): StockIncidentType { return $this->type; }
    public function getStockMovementId(): ?int { return $this->stockMovementId; }
    public function getDetail(): string { return $this->detail; }
    /** @return array<string, mixed> */
    public function getMetadata(): array { return $this->metadata; }
    public function getStatus(): StockIncidentStatus { return $this->status; }
    public function isOpen(): bool { return $this->status === StockIncidentStatus::OPEN; }
    public function getResolution(): ?string { return $this->resolution; }
    public function getResolvedBy(): ?int { return $this->resolvedBy; }
    public function getResolvedAt(): ?string { return $this->resolvedAt; }
    public function getRaisedBy(): ?int { return $this->raisedBy; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getItemName(): ?string { return $this->itemName; }
    public function getWarehouseName(): ?string { return $this->warehouseName; }
    public function getResolvedByName(): ?string { return $this->resolvedByName; }
}
