<?php

declare(strict_types=1);

namespace App\Application\UseCases\ExitOrders;

final class ExitOrderUseCases
{
    public function __construct(
        public readonly ListExitOrdersUseCase $list,
        public readonly GetExitOrderUseCase $get,
        public readonly FindExitOrderByCodeUseCase $byCode,
        public readonly IssueExitOrderUseCase $issue,
        public readonly UpdateExitOrderUseCase $update,
        public readonly ExecuteExitOrderUseCase $execute,
        public readonly RegisterExitUseCase $register,
        public readonly CloseIncompleteExitOrderUseCase $closeIncomplete,
        public readonly CancelExitOrderUseCase $cancel,
        public readonly AddExitOrderDteUseCase $addDte,
        public readonly RemoveExitOrderDteUseCase $removeDte,
        public readonly RegisterDeathOnExitLineUseCase $deathOnLine,
    ) {
    }
}
