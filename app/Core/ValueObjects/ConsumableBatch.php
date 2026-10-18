<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * What the stock module needs to know about a batch to charge a consumption or a service to it
 * (DS10), read with the company filter explicit.
 */
final readonly class ConsumableBatch
{
    /** Batch types that never receive a consumption: the animals are dead or slaughtered. */
    public const EXCLUDED_TYPES = ['INTERNAL_DEATH', 'INTERNAL_CONSUMPTION'];

    public function __construct(
        public int $id,
        public string $name,
        public bool $isActive,
        public bool $isExternal,
        public ?string $typeCode,
    ) {
    }

    /** The reason it cannot receive a consumption, or null when it can. */
    public function refusal(): ?string
    {
        return match (true) {
            $this->isExternal => "{$this->name} es un lote externo: sus animales todavía son del proveedor.",
            !$this->isActive => "{$this->name} está cerrado.",
            in_array($this->typeCode, self::EXCLUDED_TYPES, true) => "{$this->name} no recibe consumos.",
            default => null,
        };
    }
}
