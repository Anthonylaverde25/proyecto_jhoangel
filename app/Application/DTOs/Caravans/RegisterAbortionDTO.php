<?php

declare(strict_types=1);

namespace App\Application\DTOs\Caravans;

final readonly class RegisterAbortionDTO
{
    public function __construct(
        public int $caravanId,
        public int $companyId,
        public ?int $userId,
        public string $abortedOn,
        public ?string $notes,
        public bool $becomesCow
    ) {
    }
}

