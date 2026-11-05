<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Core\Enums\CastrationMethod;

final readonly class RegisterCastrationDTO
{
    /**
     * @param list<array{caravan_id: int, target_category_code?: ?string, target_category_id?: ?int}> $items
     */
    public function __construct(
        public int $companyId,
        public ?int $userId,
        public string $castratedOn,
        public ?CastrationMethod $method,
        public ?string $notes,
        public array $items
    ) {
    }
}

