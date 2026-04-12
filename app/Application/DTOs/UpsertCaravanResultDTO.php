<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class UpsertCaravanResultDTO
{
    public function __construct(
        public string $action,
        public int $id
    ) {
    }
}
