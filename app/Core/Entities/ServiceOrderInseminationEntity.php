<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Exceptions\InseminationException;

/**
 * One straw application on one female of an AI service order (semen S-B, IA6). It knows the tank
 * and the lot the straws came from and the consumption line that took them out (IA7). A wrong one
 * is voided with a reason, never deleted (IA9).
 */
final class ServiceOrderInseminationEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $serviceOrderId,
        private readonly int $femaleCaravanId,
        private readonly string $inseminatedOn,
        private readonly int $itemId,
        private readonly ?int $semenLotId,
        private readonly int $strawsUsed,
        private readonly int $warehouseId,
        private ?int $itemDocumentLineId = null,
        private readonly ?string $notes = null,
        private ?string $voidedAt = null,
        private ?string $voidReason = null,
        private readonly ?int $createdBy = null,
        private ?int $voidedBy = null,
    ) {
        if ($strawsUsed < 1) {
            throw InseminationException::on('straws_used', 'Indicá al menos una pajuela.');
        }
    }

    public function attachLine(int $lineId): void
    {
        $this->itemDocumentLineId = $lineId;
    }

    public function void(string $reason, ?int $userId, string $at): void
    {
        if (!$this->isLive()) {
            throw InseminationException::on('domain', 'La inseminación ya está anulada.');
        }
        if (trim($reason) === '') {
            throw InseminationException::on('reason', 'Indicá el motivo de la anulación.');
        }
        $this->voidedAt = $at;
        $this->voidReason = trim($reason);
        $this->voidedBy = $userId;
    }

    public function isLive(): bool { return $this->voidedAt === null; }
    public function getId(): ?int { return $this->id; }
    public function getCompanyId(): int { return $this->companyId; }
    public function getServiceOrderId(): int { return $this->serviceOrderId; }
    public function getFemaleCaravanId(): int { return $this->femaleCaravanId; }
    public function getInseminatedOn(): string { return $this->inseminatedOn; }
    public function getItemId(): int { return $this->itemId; }
    public function getSemenLotId(): ?int { return $this->semenLotId; }
    public function getStrawsUsed(): int { return $this->strawsUsed; }
    public function getWarehouseId(): int { return $this->warehouseId; }
    public function getItemDocumentLineId(): ?int { return $this->itemDocumentLineId; }
    public function getNotes(): ?string { return $this->notes; }
    public function getVoidedAt(): ?string { return $this->voidedAt; }
    public function getVoidReason(): ?string { return $this->voidReason; }
    public function getCreatedBy(): ?int { return $this->createdBy; }
    public function getVoidedBy(): ?int { return $this->voidedBy; }
}
