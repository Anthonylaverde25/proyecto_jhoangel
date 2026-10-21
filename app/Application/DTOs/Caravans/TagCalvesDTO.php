<?php

declare(strict_types=1);

namespace App\Application\DTOs\Caravans;

/**
 * The tags put on calves born without one, all on the same day.
 */
final readonly class TagCalvesDTO
{
    /**
     * @param list<array{caravan_id: int, identification: string}> $items
     */
    public function __construct(
        public string $taggedAt,
        public array $items
    ) {
    }

    /**
     * @param array{tagged_at: string, items: array<int, array{caravan_id: int|string, identification: string}>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['tagged_at'],
            array_values(array_map(static fn (array $item): array => [
                'caravan_id' => (int) $item['caravan_id'],
                'identification' => trim((string) $item['identification']),
            ], $data['items']))
        );
    }
}
