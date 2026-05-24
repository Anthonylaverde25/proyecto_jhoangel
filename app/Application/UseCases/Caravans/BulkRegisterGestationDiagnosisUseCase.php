<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\RegisterGestationDiagnosisDTO;
use App\Core\Entities\CaravanEntity;
use Illuminate\Support\Facades\DB;

final class BulkRegisterGestationDiagnosisUseCase
{
    public function __construct(
        private readonly RegisterGestationDiagnosisUseCase $registerGestationDiagnosisUseCase
    ) {
    }

    /**
     * Execute the bulk gestation diagnosis registration.
     *
     * @param RegisterGestationDiagnosisDTO[] $dtos
     * @param int $companyId
     * @return CaravanEntity[]
     */
    public function __invoke(array $dtos, int $companyId): array
    {
        return DB::transaction(function () use ($dtos, $companyId) {
            $updatedCaravans = [];
            foreach ($dtos as $dto) {
                $updatedCaravans[] = ($this->registerGestationDiagnosisUseCase)($dto, $companyId);
            }
            return $updatedCaravans;
        });
    }
}
