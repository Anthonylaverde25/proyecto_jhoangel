<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class CreateFarmDTO
{
    public function __construct(
        public string $name,
        public string $renspa,
        public ?string $location = null,
        public ?int $providerId = null,
        public ?int $companyId = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? ''),
            (string) ($data['renspa'] ?? ''),
            isset($data['location']) ? (string) $data['location'] : null,
            isset($data['provider_id']) && $data['provider_id'] !== '' ? (int) $data['provider_id'] : null,
            isset($data['company_id']) ? (int) $data['company_id'] : null
        );
    }
}

