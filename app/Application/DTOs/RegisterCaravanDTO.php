<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Core\Enums\AnimalSex;

final readonly class RegisterCaravanDTO
{
    public function __construct(
        public string $identification,
        public ?AnimalSex $sex = null,
        public ?string $category = null,
        public int $teeth = 0,
        public ?float $entryWeight = null,
        public ?string $breed = null,
        public ?int $breedId = null,
        public ?int $batchId = null,
        public ?int $farmId = null,
        public ?bool $isEmpty = null,
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
            isset($data['sex']) ? AnimalSex::from((string) $data['sex']) : null,
            isset($data['category']) ? (string) $data['category'] : null,
            (int) ($data['teeth'] ?? 0),
            isset($data['entry_weight']) ? (float) $data['entry_weight'] : null,
            isset($data['breed']) ? (string) $data['breed'] : null,
            isset($data['breed_id']) ? (int) $data['breed_id'] : null,
            isset($data['batch_id']) ? (int) $data['batch_id'] : null,
            isset($data['farm_id']) ? (int) $data['farm_id'] : null,
            isset($data['is_empty']) ? filter_var($data['is_empty'], FILTER_VALIDATE_BOOLEAN) : null,
        );
    }
}
