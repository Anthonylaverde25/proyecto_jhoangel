<?php

declare(strict_types=1);

namespace App\Core\Entities;

/**
 * A storage place of a company (D8). Every company has exactly one default warehouse, MAIN.
 */
final readonly class WarehouseEntity
{
    public const DEFAULT_CODE = 'MAIN';

    public function __construct(
        public int $id,
        public int $companyId,
        public string $code,
        public string $name,
        public bool $isDefault,
        public bool $isActive,
    ) {
    }
}
