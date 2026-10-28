<?php

declare(strict_types=1);

namespace App\Application\DTOs;

/** Identity of a partner. Contact details live in its contacts; roles have their own endpoint. */
final readonly class UpdatePartnerDTO
{
    public function __construct(
        public string $name,
        public ?string $commercialName,
        public string $cuit,
        public ?string $location,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? ''),
            isset($data['commercial_name']) ? (string) $data['commercial_name'] : null,
            (string) ($data['cuit'] ?? ''),
            isset($data['location']) ? (string) $data['location'] : null,
        );
    }
}
