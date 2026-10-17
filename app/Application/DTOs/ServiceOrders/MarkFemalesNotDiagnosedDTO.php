<?php

declare(strict_types=1);

namespace App\Application\DTOs\ServiceOrders;

final readonly class MarkFemalesNotDiagnosedDTO
{
    /**
     * @param list<array{female_caravan_id: int, reason: string, notes: ?string}> $females
     */
    public function __construct(
        public int $serviceOrderId,
        public int $companyId,
        public ?int $userId,
        public string $roundDate,
        public array $females,
        /** Declared while closing the order: the closing itself ends the exposure. */
        public bool $duringClosing = false
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{female_caravan_id: int, reason: string, notes: ?string}>
     */
    public static function rowsFromArray(array $rows): array
    {
        return array_values(array_map(static fn (array $row): array => [
            'female_caravan_id' => (int) ($row['female_caravan_id'] ?? 0),
            'reason' => (string) ($row['reason'] ?? ''),
            'notes' => isset($row['notes']) && trim((string) $row['notes']) !== '' ? trim((string) $row['notes']) : null,
        ], $rows));
    }
}
