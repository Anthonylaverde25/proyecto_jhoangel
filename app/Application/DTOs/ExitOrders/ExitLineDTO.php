<?php

declare(strict_types=1);

namespace App\Application\DTOs\ExitOrders;

use App\Core\Enums\CalfAtFootDecision;

/** One animal of a load that leaves, with what was agreed for it (all optional) and its calf. */
final readonly class ExitLineDTO
{
    public function __construct(
        public int $caravanId,
        public ?float $weightKg = null,
        public ?float $pricePerKg = null,
        public ?float $amount = null,
        public ?CalfAtFootDecision $calfDecision = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $number = fn (string $key) => isset($row[$key]) && $row[$key] !== '' ? (float) $row[$key] : null;

        return new self(
            (int) $row['caravan_id'],
            $number('weight_kg'),
            $number('price_per_kg'),
            $number('amount'),
            isset($row['calf_decision']) ? CalfAtFootDecision::tryFrom((string) $row['calf_decision']) : null,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<self>
     */
    public static function listFromArray(array $rows): array
    {
        return array_map(fn (array $row) => self::fromArray($row), array_values($rows));
    }
}
