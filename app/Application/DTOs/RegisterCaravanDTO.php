<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class RegisterCaravanDTO
{
    public function __construct(
        public string $identification,
        public ?string $category = null,
        public int $teeth = 0,
        public ?float $entryWeight = null,
        public ?string $breed = null,
        public ?string $sex = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['identification'] ?? ''),
            isset($data['category']) ? (string) $data['category'] : null,
            (int) ($data['teeth'] ?? 0),
            isset($data['entry_weight']) ? (float) $data['entry_weight'] : null,
            isset($data['breed']) ? (string) $data['breed'] : null,
            isset($data['sex']) ? (string) $data['sex'] : null,
        );
    }
}
