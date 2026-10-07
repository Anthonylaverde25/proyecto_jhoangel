<?php

declare(strict_types=1);

namespace App\Application\DTOs\EntryOrders;

/**
 * A DTE as loaded from the screen: the document and how many head it declares. No caravans: they
 * are written down when the animals arrive.
 */
final readonly class LoadDteDTO
{
    public function __construct(
        public string $dteNumber,
        public string $dteDate,
        public int $headCount,
        public ?string $observations
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
            headCount: (int) $data['head_count'],
            observations: $observations !== '' ? $observations : null
        );
    }
}
