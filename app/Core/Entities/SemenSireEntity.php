<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Exceptions\SemenException;

/**
 * The bull a semen comes from (plan part 3). Never a caravan: an external bull, or an own bull linked
 * to its caravan, whose name and breed are read from it (SS2). Deactivated, never deleted (SS4).
 */
final readonly class SemenSireEntity
{
    public function __construct(
        public ?int $id,
        public int $companyId,
        public string $name,
        public ?string $registryCode,
        public ?int $breedId,
        public ?string $studName,
        public ?int $caravanId,
        public ?string $notes,
        public bool $isActive = true,
        public ?string $breedName = null,
        public ?string $caravanTag = null,
        public int $activeItems = 0,
    ) {
    }

    public static function cleanName(string $name): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($clean === '' || mb_strlen($clean) > 100) {
            throw SemenException::on('name', 'Indicá el nombre del toro, hasta 100 caracteres.');
        }

        return $clean;
    }

    /** Upper case, single spaces; empty is no registry. */
    public static function cleanRegistry(?string $code): ?string
    {
        $clean = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $code) ?? ''));
        if (mb_strlen($clean) > 40) {
            throw SemenException::on('registry_code', 'El registro tiene hasta 40 caracteres.');
        }

        return $clean === '' ? null : $clean;
    }

    public function isOwnBull(): bool
    {
        return $this->caravanId !== null;
    }

    /** SS3: a sire with active semen items stays active. */
    public function withActive(bool $active, bool $hasActiveItems): self
    {
        if (!$active && $hasActiveItems) {
            throw SemenException::on('domain', "{$this->name} tiene semen activo en el inventario: desactivá antes esos ítems.");
        }

        return new self($this->id, $this->companyId, $this->name, $this->registryCode, $this->breedId, $this->studName, $this->caravanId, $this->notes, $active);
    }

    public function label(): string
    {
        return $this->registryCode === null ? $this->name : "{$this->name} ({$this->registryCode})";
    }
}
