<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Exceptions\SemenException;

/**
 * Straws moved without a lot (SL6): an entry now, an insemination in S-B. What is identified goes to
 * lots without touching the ledger (SU3, SU7); the notice closes when nothing is pending (SU5).
 */
final class SemenUnidentifiedEntryEntity
{
    public const OPEN = 'OPEN';
    public const RESOLVED = 'RESOLVED';
    public const VOIDED = 'VOIDED';

    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $itemId,
        private readonly int $warehouseId,
        private readonly int $lineId,
        private ?int $incidentId,
        private readonly int $direction,
        private readonly string $quantity,
        private string $identifiedQuantity = '0.0000',
        private string $status = self::OPEN,
    ) {
    }

    public function pending(): string
    {
        return bcsub($this->quantity, $this->identifiedQuantity, 4);
    }

    /** Adds what was just identified; refuses more than is pending. */
    public function identify(string $quantity): void
    {
        if ($this->status !== self::OPEN) {
            throw SemenException::on('domain', 'Estas pajuelas ya no tienen nada pendiente de identificar.');
        }
        if (bccomp($quantity, '0', 4) <= 0) {
            throw SemenException::on('lots', 'Indicá cuántas pajuelas van a cada partida.');
        }
        if (bccomp($quantity, $this->pending(), 4) > 0) {
            throw SemenException::on('lots', 'Quedan ' . self::format($this->pending()) . ' pajuelas por identificar: no se puede asignar más.');
        }
        $this->identifiedQuantity = bcadd($this->identifiedQuantity, $quantity, 4);
        if (bccomp($this->identifiedQuantity, $this->quantity, 4) === 0) {
            $this->status = self::RESOLVED;
        }
    }

    /** SU8: its line was reversed. */
    public function void(): void
    {
        $this->status = self::VOIDED;
    }

    public function attachIncident(int $incidentId): void
    {
        $this->incidentId = $incidentId;
    }

    public static function format(string $quantity): string
    {
        $trimmed = rtrim(rtrim($quantity, '0'), '.');

        return str_replace('.', ',', $trimmed === '' ? '0' : $trimmed);
    }

    public function getId(): ?int { return $this->id; }
    public function getCompanyId(): int { return $this->companyId; }
    public function getItemId(): int { return $this->itemId; }
    public function getWarehouseId(): int { return $this->warehouseId; }
    public function getLineId(): int { return $this->lineId; }
    public function getIncidentId(): ?int { return $this->incidentId; }
    public function getDirection(): int { return $this->direction; }
    public function getQuantity(): string { return $this->quantity; }
    public function getIdentifiedQuantity(): string { return $this->identifiedQuantity; }
    public function getStatus(): string { return $this->status; }
    public function isOpen(): bool { return $this->status === self::OPEN; }
}
