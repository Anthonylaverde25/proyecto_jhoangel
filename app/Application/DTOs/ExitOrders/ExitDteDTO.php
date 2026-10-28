<?php

declare(strict_types=1);

namespace App\Application\DTOs\ExitOrders;

/** A DTE loaded on an exit: number, date and head (01 §6). */
final readonly class ExitDteDTO
{
    public function __construct(
        public string $dteNumber,
        public string $dteDate,
        public int $headCount,
        public ?string $observations = null,
    ) {
    }

    /** @param array<string, mixed>|null $data */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null || trim((string) ($data['dte_number'] ?? '')) === '') {
            return null;
        }

        return new self(
            strtoupper(trim((string) $data['dte_number'])),
            (string) $data['dte_date'],
            (int) $data['head_count'],
            isset($data['observations']) ? (string) $data['observations'] : null,
        );
    }
}
