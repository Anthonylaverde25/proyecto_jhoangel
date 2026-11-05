<?php

declare(strict_types=1);

namespace App\Application\DTOs\Stock;

use App\Application\Services\Stock\LedgerResult;

/**
 * A consumption recorded for an AI client, with the transfers that completed its warehouse and how the
 * item's balances stand after it.
 */
final readonly class AiConsumptionResultDTO
{
    /**
     * @param list<array{number: int, from: string, quantity: string}> $transfers
     * @param list<array{warehouse: string, quantity: string}> $stockAfter
     */
    public function __construct(
        public LedgerResult $ledger,
        public array $transfers,
        public array $stockAfter,
    ) {
    }
}
