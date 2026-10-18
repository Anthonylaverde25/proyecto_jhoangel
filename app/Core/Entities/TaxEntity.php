<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\TaxScope;

/**
 * A tax of the fixed catalog (DS15). is_recoverable decides whether it is a tax credit or part of
 * the cost (DS17); scope decides whether it goes on a line or on the whole document (DS16).
 */
final readonly class TaxEntity
{
    public const VAT = 'VAT';

    /** @param list<TaxRateEntity> $rates */
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $kind,
        public string $jurisdiction,
        public TaxScope $scope,
        public bool $isRecoverable,
        public bool $isActive,
        public array $rates = [],
    ) {
    }

    public function isVat(): bool
    {
        return $this->code === self::VAT;
    }
}
