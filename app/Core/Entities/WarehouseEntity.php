<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Exceptions\WarehouseException;
use App\Core\ValueObjects\WarehouseCode;

/**
 * A storage place of a company (D8). Exactly one is the default, and the default is always active
 * (DD3): every provider document enters there. Never deleted, only deactivated (DD4).
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
        return new self($this->id, $this->companyId, $code->value, self::cleanName($name), $this->isDefault, $this->isActive);
    }

    public function asDefault(): self
    {
        if (!$this->isActive) {
            throw WarehouseException::on('new_default_id', "El almacén {$this->code} está inactivo.");
        }

        return new self($this->id, $this->companyId, $this->code, $this->name, true, true);
    }

    public function notDefault(): self
    {
        return new self($this->id, $this->companyId, $this->code, $this->name, false, $this->isActive);
    }

    public function deactivated(): self
    {
        if ($this->isDefault) {
            throw WarehouseException::on('new_default_id', 'Elegí qué almacén pasa a ser el predeterminado.');
        }

        return new self($this->id, $this->companyId, $this->code, $this->name, false, false);
    }

    public function activated(): self
    {
        return new self($this->id, $this->companyId, $this->code, $this->name, $this->isDefault, true);
    }

    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }
}
