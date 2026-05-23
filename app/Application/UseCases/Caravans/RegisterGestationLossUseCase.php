<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Entities\CaravanEntity;
use App\Core\Enums\AnimalCategory;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\ValueObjects\FemaleReproductiveDetails;
use Illuminate\Support\Facades\DB;

final class RegisterGestationLossUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository
    ) {
    }

    public function __invoke(
        int $caravanId,
        int $lossReasonId,
        ?string $lossNotes,
        string $lossDate
    ): CaravanEntity {
        return DB::transaction(function () use ($caravanId, $lossReasonId, $lossNotes, $lossDate) {
            $caravan = $this->caravanRepository->findById($caravanId);
            if ($caravan === null) {
                throw new DomainException("La caravana con ID {$caravanId} no existe.");
            }

            $activeGestation = $caravan->getActiveGestation();
            if ($activeGestation === null) {
                throw new DomainException("La caravana no tiene un proceso de gestación activo.");
            }

            // Close the gestation as unsuccessful (success = false)
            $activeGestation->closeGestation(
                success: false,
                endDate: $lossDate,
                notes: "Gestation ended with loss.",
                lossReasonId: $lossReasonId,
                lossNotes: $lossNotes
            );

            // Update female details to empty
            $reproductiveDetails = $caravan->getReproductiveDetails();
            $category = $caravan->getCategory() ?? AnimalCategory::VAQUILLONA;
            $arrivalCategory = $reproductiveDetails !== null 
                ? $reproductiveDetails->getArrivalCategory() 
                : $category;

            $caravan->recordFemaleDetails(new FemaleReproductiveDetails(true, $arrivalCategory));

            return $this->caravanRepository->save($caravan);
        });
    }
}
