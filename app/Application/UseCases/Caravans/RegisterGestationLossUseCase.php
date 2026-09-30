<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Entities\CaravanEntity;
use App\Core\Enums\AnimalCategory;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IAnimalCategoryRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\ValueObjects\FemaleReproductiveDetails;
use Illuminate\Support\Facades\DB;

final class RegisterGestationLossUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly IAnimalCategoryRepository $animalCategoryRepository
    ) {
    }

    /**
     * @param bool $promoteToCow true for a full-term calving whose calf was born dead: the female
     *        calved, so she is a cow from now on, as she would be with a live calf.
     */
    public function __invoke(
        int $caravanId,
        int $lossReasonId,
        ?string $lossNotes,
        string $lossDate,
        bool $promoteToCow = false
    ): CaravanEntity {
        return DB::transaction(function () use ($caravanId, $lossReasonId, $lossNotes, $lossDate, $promoteToCow) {
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
            $arrivalCategory = $reproductiveDetails !== null 
                ? $reproductiveDetails->getArrivalCategory() 
                : AnimalCategory::VACA;

            $caravan->recordFemaleDetails(new FemaleReproductiveDetails(true, $arrivalCategory));

            if ($promoteToCow) {
                $cowCategoryId = $this->animalCategoryRepository->findByCode('VACA')?->getId();

                if ($cowCategoryId !== null) {
                    $caravan->setCategoryId($cowCategoryId);
                }
            }

            return $this->caravanRepository->save($caravan);
        });
    }
}
