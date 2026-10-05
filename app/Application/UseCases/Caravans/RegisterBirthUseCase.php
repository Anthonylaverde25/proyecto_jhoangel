<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\RegisterBirthDTO;
use App\Core\Entities\CaravanEntity;
use App\Core\Entities\LineageEntity;
use App\Core\Enums\AnimalCategory;
use App\Core\Enums\AnimalSex;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IAnimalCategoryRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\ICaravanLineageRepository;
use App\Core\ValueObjects\CaravanNumber;
use App\Core\ValueObjects\FemaleReproductiveDetails;
use App\Core\ValueObjects\SireEntry;
use App\Core\Services\BatchWeightService;
use App\Application\DTOs\RecordCaravanWeightDTO;
use Illuminate\Support\Facades\DB;
use App\Core\Enums\BatchWeightCause;

final class RegisterBirthUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly ICaravanLineageRepository $lineageRepository,
        private readonly RecordCaravanWeightUseCase $recordCaravanWeightUseCase,
        private readonly BatchWeightService $batchWeightService,
        private readonly IAnimalCategoryRepository $animalCategoryRepository
    ) {
    }

    /**
     * @param bool $recalculateBatchWeight false when the caller registers many calvings and
     *        recalculates each batch once at the end (PAR-01), instead of once per calf.
     */
    public function __invoke(RegisterBirthDTO $dto, bool $recalculateBatchWeight = true): CaravanEntity
    {
        return DB::transaction(function () use ($dto, $recalculateBatchWeight) {
            // 1. Validate mother
            $mother = $this->caravanRepository->findById($dto->motherId);
            if ($mother === null) {
                throw new DomainException("La madre especificada con ID {$dto->motherId} no existe.");
            }
            if ($mother->getSex() !== AnimalSex::FEMALE) {
                throw new DomainException("El animal especificado como madre debe ser hembra.");
            }

            // The calf is born where its mother is. The batch the caller sent is only a fallback for
            // a mother that is in no batch.
            $calfBatchId = $mother->getBatchId() ?? $dto->batchId;

            // 2. Resolve active gestation first
            $activeGestation = null;
            if ($dto->gestationId !== null) {
                foreach ($mother->getGestations() as $gestation) {
                    if ($gestation->getId() === $dto->gestationId) {
                        $activeGestation = $gestation;
                        break;
                    }
                }
            } else {
                $activeGestation = $mother->getActiveGestation();
            }

            // 3. Validate and resolve father
            $fatherId = $dto->fatherId;
            $fatherIdentification = null;

            if ($fatherId !== null) {
                $father = $this->caravanRepository->findById($fatherId);
                if ($father === null) {
                    throw new DomainException("El padre especificado con ID {$fatherId} no existe.");
                }
                if ($father->getSex() !== AnimalSex::MALE) {
                    throw new DomainException("El animal especificado como padre debe ser macho.");
                }
                $fatherIdentification = $father->getIdentification()->getValue();
            } elseif ($activeGestation !== null) {
                $confirmedSire = $activeGestation->getConfirmedSire();
                if ($confirmedSire !== null) {
                    $fatherId = $confirmedSire->getSireId();
                    $fatherIdentification = $confirmedSire->getSireIdentification();
                } else {
                    $sires = $activeGestation->getSires();
                    if (count($sires) === 1) {
                        $singleSire = $sires[0];
                        $fatherId = $singleSire->getSireId();
                        $fatherIdentification = $singleSire->getSireIdentification();
                    }
                }
            }

            // 4. Validate and create calf identification
            $calfNumber = new CaravanNumber($dto->calfIdentification);
            $existingCalf = $this->caravanRepository->findByIdentification($calfNumber);
            if ($existingCalf !== null) {
                throw new DomainException("Ya existe un animal con la identificación '{$dto->calfIdentification}' en esta compañía.");
            }

            $calfSex = AnimalSex::from($dto->calfSex);
            $calfCategory = $dto->calfCategory !== null ? AnimalCategory::tryFrom($dto->calfCategory) : null;
            if ($calfCategory === null) {
                $calfCategory = AnimalCategory::TERNERO;
            }

            // Resolve calf category ID
            $terneroCatEntity = $this->animalCategoryRepository->findByCode('TERNERO');
            $calfCategoryId = $terneroCatEntity?->getId();

            // Initialize female details for the calf if it is a female
            $calfReproductiveDetails = null;
            if ($calfSex === AnimalSex::FEMALE) {
                $calfReproductiveDetails = new FemaleReproductiveDetails(true, $calfCategory);
            }

            // 5. Create and save Calf Entity
            $calfEntity = new CaravanEntity(
                id: null,
                identification: $calfNumber,
                teeth: $dto->calfTeeth,
                entryWeight: $dto->calfWeight,
                exitWeight: null,
                breedId: $dto->calfBreedId,
                breedName: null,
                colorId: $dto->calfColorId,
                colorName: null,
                sex: $calfSex,
                entryDate: new \DateTime($dto->birthDate),
                createdAt: null,
                batchId: $calfBatchId,
                companyId: $mother->getCompanyId(),
                batchName: null,
                currentWeight: $dto->calfWeight,
                reproductiveDetails: $calfReproductiveDetails,
                gestations: [],
                lineage: null,
                categoryId: $calfCategoryId
            );

            $savedCalf = $this->caravanRepository->save($calfEntity);

            // 6. Close gestation for the mother and confirm sire
            if ($activeGestation !== null) {
                $activeGestation->closeGestation(
                    success: true,
                    endDate: $dto->birthDate,
                    notes: "Closed via birth registration of calf ID: {$savedCalf->getId()}."
                );

                if ($fatherId !== null && $fatherIdentification !== null) {
                    $sireExists = false;
                    foreach ($activeGestation->getSires() as $sire) {
                        if ($sire->getSireId() === $fatherId) {
                            $sireExists = true;
                            break;
                        }
                    }

                    if ($sireExists) {
                        $activeGestation->confirmSire($fatherId);
                    } else {
                        $activeGestation->addSire(new SireEntry(
                            $fatherId,
                            $fatherIdentification,
                            true
                        ));
                    }
                }
            }

            // 7. Create and save Lineage
            $lineageEntity = new LineageEntity(
                id: null,
                caravanId: $savedCalf->getId(),
                motherId: $mother->getId(),
                motherIdentification: $mother->getIdentification()->getValue(),
                fatherId: $fatherId,
                fatherIdentification: $fatherIdentification,
                gestationId: $activeGestation?->getId(),
                birthDate: $dto->birthDate,
                isNursing: true
            );

            $this->lineageRepository->save($lineageEntity);

            // 8. Promote Mother: Vaquillona -> Vaca (after first calving)
            $vacaCatEntity = $this->animalCategoryRepository->findByCode('VACA');
            if ($vacaCatEntity) {
                $mother->setCategoryId($vacaCatEntity->getId());
            }

            // Update Mother state to empty
            $motherReproductiveDetails = $mother->getReproductiveDetails();
            $arrivalCategory = $motherReproductiveDetails !== null 
                ? $motherReproductiveDetails->getArrivalCategory() 
                : AnimalCategory::VACA;

            $mother->recordFemaleDetails(new FemaleReproductiveDetails(true, $arrivalCategory));
            $this->caravanRepository->save($mother);

            // 8. Record initial weight in caravan_weights if provided
            $weightRecorded = false;
            if ($dto->calfWeight !== null) {
                ($this->recordCaravanWeightUseCase)(new RecordCaravanWeightDTO(
                    $savedCalf->getId(),
                    $dto->calfWeight,
                    $dto->birthDate,
                    "Initial birth weight"
                ));
                $weightRecorded = true;
            }

            // 9. Recalculate Batch weight for the calf's batch
            if ($weightRecorded && $recalculateBatchWeight) {
                // A newborn grows the set: for this series that is a change of composition.
                $this->batchWeightService->recalculateBatchWeight($calfBatchId, BatchWeightCause::MOVEMENT_IN);
            }

            // Reload calf with lineage relations
            return $this->caravanRepository->findById($savedCalf->getId());
        });
    }
}
