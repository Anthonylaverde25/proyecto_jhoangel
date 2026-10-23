<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\WarehouseKind;
use App\Core\Exceptions\WarehouseException;
use App\Core\ValueObjects\WarehouseCode;

/**
 * A storage place of a company (D8). It is a general warehouse or a semen tank (S-0). There is one
 * default per kind and the default is always active (DD3): every provider document enters the
 * general one, which always exists; the first tank becomes the tanks' default. Never deleted, only
 * deactivated (DD4).
 */
final readonly class WarehouseEntity
{
    public const DEFAULT_CODE = 'ALM-01';
    public const DEFAULT_NAME = 'Almacén principal';

    public function __construct(
        public int $id,
        public int $companyId,
        public string $code,
        public string $name,
        public bool $isDefault,
        public bool $isActive,
        public WarehouseKind $kind = WarehouseKind::GENERAL,
    ) {
    }

    public static function cleanName(string $name): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($clean === '' || mb_strlen($clean) > 100) {
            throw WarehouseException::on('name', 'Indicá un nombre de hasta 100 caracteres.');
        }

        return $clean;
    }

    public function renamed(WarehouseCode $code, string $name): self
    {
        return new self($this->id, $this->companyId, $code->value, self::cleanName($name), $this->isDefault, $this->isActive, $this->kind);
    }

    /**
     * SW1: the kind is fixed once the warehouse has movements. The general default never changes kind
     * (there is always one); a tank that stops being a tank stops being the tanks' default.
     */
    public function retyped(WarehouseKind $kind, bool $hasMovements): self
    {
        if ($kind === $this->kind) {
            return $this;
        }
        if ($this->isDefault && $this->kind === WarehouseKind::GENERAL) {
            throw WarehouseException::on('kind', 'El almacén predeterminado no cambia de tipo: elegí antes otro predeterminado.');
        }
        if ($hasMovements) {
            throw WarehouseException::on('kind', "El tipo de {$this->code} ya no cambia: tiene movimientos.");
        }

        return new self($this->id, $this->companyId, $this->code, $this->name, false, $this->isActive, $kind);
    }

    public function asDefault(): self
    {
        if (!$this->isActive) {
            throw WarehouseException::on('new_default_id', "El almacén {$this->code} está inactivo.");
        }

        return new self($this->id, $this->companyId, $this->code, $this->name, true, true, $this->kind);
    }

    public function notDefault(): self
    {
        return new self($this->id, $this->companyId, $this->code, $this->name, false, $this->isActive, $this->kind);
    }

    public function deactivated(): self
    {
        if ($this->isDefault) {
            throw WarehouseException::on('new_default_id', 'Elegí qué almacén pasa a ser el predeterminado.');
        }

        return new self($this->id, $this->companyId, $this->code, $this->name, false, false, $this->kind);
    }

    public function activated(): self
    {
        return new self($this->id, $this->companyId, $this->code, $this->name, $this->isDefault, true, $this->kind);
    }

    public function isCryoTank(): bool
    {
        return $this->kind === WarehouseKind::CRYO_TANK;
    }

    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }
}
