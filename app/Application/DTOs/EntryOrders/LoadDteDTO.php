<?php

declare(strict_types=1);

namespace App\Application\DTOs\EntryOrders;

/**
 * A DTE as loaded from the screen: the document and the caravans it lists. No arrival date and no
 * weights: those belong to the reception.
 */
final readonly class LoadDteDTO
{
    /**
     * @param list<array{caravana: string, sex: ?string, breed_position: ?int}> $animals
     */
    public function __construct(
        public string $dteNumber,
        public string $dteDate,
        public ?string $observations,
        public array $animals
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $observations = trim((string) ($data['observations'] ?? ''));

        return new self(
            dteNumber: strtoupper(trim((string) $data['dte_number'])),
            dteDate: (string) $data['dte_date'],
            observations: $observations !== '' ? $observations : null,
            animals: array_map(fn (array $row) => [
                'caravana' => trim((string) ($row['caravana'] ?? '')),
                'sex' => isset($row['sex']) && $row['sex'] !== '' ? strtoupper((string) $row['sex']) : null,
                'breed_position' => isset($row['breed_position']) && $row['breed_position'] !== '' ? (int) $row['breed_position'] : null,
            ], array_values($data['animals'] ?? []))
        );
    }
}
