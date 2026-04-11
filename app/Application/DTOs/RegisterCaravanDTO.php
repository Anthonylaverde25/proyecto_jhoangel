<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class RegisterCaravanDTO
{
    public function __construct(
        public int $identification,
        public string $category,
        public int $teeth,
        public ?float $entryWeight = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['identification'],
            (string) $data['category'],
            (int) $data['teeth'],
            isset($data['entry_weight']) ? (float) $data['entry_weight'] : null
        );
    }
}
