<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class CreateProviderDTO
{
    public function __construct(
        public string $name,
        public ?string $commercialName,
        public string $cuit,
        public ?string $location,
        public ?string $email,
        public ?string $phone,
        /** @var array<string, mixed>[] */
        public array $farms = []
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? ''),
            isset($data['commercial_name']) ? (string) $data['commercial_name'] : null,
            (string) ($data['cuit'] ?? ''),
            isset($data['location']) ? (string) $data['location'] : null,
            isset($data['email']) ? (string) $data['email'] : null,
            isset($data['phone']) ? (string) $data['phone'] : null,
            (array) ($data['farms'] ?? [])
        );
    }
}
