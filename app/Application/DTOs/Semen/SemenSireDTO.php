<?php

declare(strict_types=1);

namespace App\Application\DTOs\Semen;

final readonly class SemenSireDTO
{
    public function __construct(
        public ?string $name,
        public ?string $registryCode,
        public ?int $breedId,
        public ?string $studName,
        public ?int $caravanId,
        public ?string $notes,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $text = fn (string $key) => isset($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;

        return new self(
            $text('name'),
            $text('registry_code'),
            isset($data['breed_id']) ? (int) $data['breed_id'] : null,
            $text('stud_name'),
            isset($data['caravan_id']) ? (int) $data['caravan_id'] : null,
            $text('notes'),
        );
    }
}
