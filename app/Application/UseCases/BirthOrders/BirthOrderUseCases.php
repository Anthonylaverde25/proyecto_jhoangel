<?php

declare(strict_types=1);

namespace App\Application\UseCases\BirthOrders;

final class BirthOrderUseCases
{
    public function __construct(
        public readonly CreateBirthOrderUseCase $create,
        public readonly UpdateBirthOrderDraftUseCase $updateDraft,
        public readonly IssueBirthOrderUseCase $issue,
        public readonly GetBirthOrderUseCase $get,
        public readonly FindBirthOrderByCodeUseCase $findByCode,
        public readonly ListBirthOrdersUseCase $list,
        public readonly MarkBirthOrderPrintedUseCase $markPrinted,
        public readonly CloseIncompleteBirthOrderUseCase $closeIncomplete,
        public readonly CancelBirthOrderUseCase $cancel,
        public readonly ExecuteBirthOrderUseCase $execute,
        public readonly RegisterBirthsUseCase $register,
        public readonly ListOpenBirthOrderMothersUseCase $openMothers,
    ) {
    }
}
