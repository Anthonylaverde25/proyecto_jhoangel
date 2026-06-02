<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class BulkWeanDTO
{
    /**
     * @param WeanCaravanDTO[] $weanings
     */
    public function __construct(
        public array $weanings
    ) {
    }
}
