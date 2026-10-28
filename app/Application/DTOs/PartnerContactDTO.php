<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class PartnerContactDTO
{
    public function __construct(
        public string $name,
        public string $phone,
        public ?string $position = null,
        public ?string $email = null,
        public ?string $notes = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? ''),
            (string) ($data['phone'] ?? ''),
            isset($data['position']) ? (string) $data['position'] : null,
            isset($data['email']) ? (string) $data['email'] : null,
            isset($data['notes']) ? (string) $data['notes'] : null,
        );
    }
}
