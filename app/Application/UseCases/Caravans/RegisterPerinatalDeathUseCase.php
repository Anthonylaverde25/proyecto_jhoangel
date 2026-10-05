<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Entities\CaravanEntity;
use App\Core\Enums\AnimalCategory;
use App\Core\Enums\AnimalSex;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IAnimalCategoryRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\ValueObjects\FemaleReproductiveDetails;
use Illuminate\Support\Facades\DB;

/**
 * A full-term calving whose calf was born alive and died at foot, shortly after birth (M on the
 * PAR-01 sheet).
 *
 * The mother calved a live calf, so her gestation closes SUCCESSFUL and she becomes a cow, exactly as
 * with a calf that lives: the death is charged to the calf, not to her reproductive record. Nothing
 * of the calf is created — it never got a tag —: no caravan, no lineage, no weight. What is known of
 * it (its sex, the observed cause) stays on the birth order line.
 */
final class RegisterPerinatalDeathUseCase
{
    private const GESTATION_NOTE = 'Parto con ternero muerto al pie (muerte perinatal).';

    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly IAnimalCategoryRepository $animalCategoryRepository
    ) {
    }

    /**
     * @throws DomainException
     */
    public function __invoke(int $motherId, string $birthDate): CaravanEntity
    {
        return DB::transaction(function () use ($motherId, $birthDate): CaravanEntity {
            $mother = $this->caravanRepository->findById($motherId)
                ?? throw new DomainException("La madre especificada con ID {$motherId} no existe.");

            if ($mother->getSex() !== AnimalSex::FEMALE) {
                throw new DomainException('El animal especificado como madre debe ser hembra.');
            }

            $mother->getActiveGestation()?->closeGestation(
                success: true,
                endDate: $birthDate,
                notes: self::GESTATION_NOTE
            );

            // She calved: a cow from now on, the same rule as a live calving.
            $cowCategoryId = $this->animalCategoryRepository->findByCode('VACA')?->getId();
            if ($cowCategoryId !== null) {
                $mother->setCategoryId($cowCategoryId);
            }

            $arrivalCategory = $mother->getReproductiveDetails()?->getArrivalCategory() ?? AnimalCategory::VACA;
            $mother->recordFemaleDetails(new FemaleReproductiveDetails(true, $arrivalCategory));

            return $this->caravanRepository->save($mother);
        });
    }
}
