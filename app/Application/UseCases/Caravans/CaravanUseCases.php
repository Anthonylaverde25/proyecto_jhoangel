<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

final class CaravanUseCases
{
    public function __construct(
        public readonly ListCaravansUseCase $list,
        public readonly UpsertCaravanUseCase $upsert,
        public readonly ImportCaravansUseCase $import,
    ) {
    }
}
