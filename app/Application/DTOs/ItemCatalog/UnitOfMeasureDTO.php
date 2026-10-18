<?php

declare(strict_types=1);

namespace App\Application\DTOs\ItemCatalog;

use App\Core\Enums\UnitDimension;

/**
 * Unit data from a create or update request. On update, null means "not sent".
 */
final readonly class UnitOfMeasureDTO
{
    public function __construct(
        public ?string $code,
        public ?string $name,
        public ?string $symbol,
        public ?UnitDimension $dimension,
        public bool $factorSent,
        public ?string $factorToBase,
        public ?int $displayOrder,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $factor = $data['factor_to_base'] ?? null;

        return new self(
            isset($data['code']) ? (string) $data['code'] : null,
            isset($data['name']) ? (string) $data['name'] : null,
            isset($data['symbol']) ? (string) $data['symbol'] : null,
            isset($data['dimension']) ? UnitDimension::from((string) $data['dimension']) : null,
            array_key_exists('factor_to_base', $data),
            $factor === null || $factor === '' ? null : (string) $factor,
            isset($data['display_order']) ? (int) $data['display_order'] : null,
        );
    }
}
