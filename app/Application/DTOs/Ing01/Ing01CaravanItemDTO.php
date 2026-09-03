<?php

declare(strict_types=1);

namespace App\Application\DTOs\Ing01;

use App\Core\Services\CaravanValueParser;

final class Ing01CaravanItemDTO
{
    public function __construct(
        public readonly string $identification,
        public readonly ?string $category = null,
        public readonly ?string $sex = null,
        public readonly ?string $breed = null,
        public readonly ?int $teeth = null,
        public readonly ?float $entryWeight = null,
        public readonly ?string $observations = null
    ) {
    }

    /**
     * Create DTO from an array.
     *
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $ident = trim((string)($data['caravana'] ?? $data['identification'] ?? ''));
        
        $rawTeeth = $data['teeth'] ?? $data['denticion'] ?? null;
        $teeth = $rawTeeth !== null && $rawTeeth !== '' ? CaravanValueParser::parseTeeth($rawTeeth) : null;
        
        $rawWeight = $data['entry_weight'] ?? $data['weight'] ?? $data['peso'] ?? null;
        $weight = $rawWeight !== null && $rawWeight !== '' ? CaravanValueParser::parseWeight($rawWeight) : null;

        return new self(
            identification: $ident,
            category: isset($data['category']) ? (string)$data['category'] : (isset($data['categoria']) ? (string)$data['categoria'] : null),
            sex: isset($data['sex']) ? (string)$data['sex'] : (isset($data['sexo']) ? (string)$data['sexo'] : null),
            breed: isset($data['breed']) ? (string)$data['breed'] : (isset($data['raza']) ? (string)$data['raza'] : null),
            teeth: $teeth,
            entryWeight: $weight,
            observations: isset($data['observations']) ? (string)$data['observations'] : (isset($data['observaciones']) ? (string)$data['observaciones'] : null)
        );
    }
}
