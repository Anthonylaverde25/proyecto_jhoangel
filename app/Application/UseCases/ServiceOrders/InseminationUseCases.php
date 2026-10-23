<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

/** Aggregator of the AI order (semen S-B), so the controller stays thin. */
final class InseminationUseCases
{
    public function __construct(
        public readonly RegisterInseminationsUseCase $register,
        public readonly VoidInseminationUseCase $void,
        public readonly EndInseminationServiceUseCase $endService,
        public readonly AddRepasoBullsUseCase $addRepasoBulls,
        public readonly GetSemenCoverageUseCase $coverage,
        public readonly ListInseminationsUseCase $list,
        public readonly \App\Core\Interfaces\IServiceOrderInseminationRepository $inseminations,
    ) {
    }
}
