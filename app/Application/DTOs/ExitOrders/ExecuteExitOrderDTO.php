<?php

declare(strict_types=1);

namespace App\Application\DTOs\ExitOrders;

/** One load of an issued order that left: the date, its rows and, when in hand, its DTE. */
final readonly class ExecuteExitOrderDTO
{
    /** @param list<ExitLineDTO> $lines */
    public function __construct(
        public int $orderId,
        public int $companyId,
        public ?int $userId,
        public string $exitDate,
        public array $lines,
        public ?ExitDteDTO $dte = null,
        public ?int $exitBatchId = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(int $orderId, array $data, int $companyId, ?int $userId): self
    {
        return new self(
            $orderId,
            $companyId,
            $userId,
            (string) $data['exit_date'],
            ExitLineDTO::listFromArray($data['lines'] ?? []),
            ExitDteDTO::fromArray($data['dte'] ?? null),
            isset($data['exit_batch_id']) ? (int) $data['exit_batch_id'] : null,
        );
    }
}
