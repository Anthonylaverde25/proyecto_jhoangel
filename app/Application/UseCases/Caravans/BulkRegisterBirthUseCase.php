<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\RegisterBirthDTO;
use App\Core\Entities\CaravanEntity;
use Illuminate\Support\Facades\DB;

final class BulkRegisterBirthUseCase
{
    public function __construct(
        private readonly RegisterBirthUseCase $registerBirthUseCase
    ) {
    }

    /**
     * @param RegisterBirthDTO[] $dtos
     * @return CaravanEntity[]
     */
    public function __invoke(array $dtos): array
    {
        return DB::transaction(function () use ($dtos) {
            $createdCaravans = [];
            foreach ($dtos as $dto) {
                $createdCaravans[] = ($this->registerBirthUseCase)($dto);
            }
            return $createdCaravans;
        });
    }
}
