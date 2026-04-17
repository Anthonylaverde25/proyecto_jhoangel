<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class CreateFarmDTO
{
    public function __construct(
        public string $name,
        public ?string $location,
        public int $providerId
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? ''),
            isset($data['location']) ? (string) $data['location'] : null,
            (int) ($data['provider_id'] ?? 0)
        );
    }
}
