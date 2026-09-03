<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\ImportCaravansDTO;
use App\Core\Entities\CaravanEntity;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Services\CaravanValueParser;
use App\Core\ValueObjects\CaravanNumber;
use App\Core\Entities\WorkdayEntity;
use App\Core\Enums\WorkType;
use App\Core\Interfaces\IWorkdayRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\IBatchTypeRepository;
use App\Core\Entities\BatchEntity;
use App\Core\Services\WorkdayCodeGenerator;
use App\Core\Interfaces\IBreedRepository;
use App\Core\Services\BreedMatcherService;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Services\CaravanTraceabilityService;
use App\Core\ValueObjects\FemaleReproductiveDetails;

final class ImportCaravansUseCase
{
    public function __construct(
        private readonly ICaravanRepository $repository,
        private readonly IWorkdayRepository $workdayRepository,
        private readonly IBatchRepository $batchRepository,
        private readonly WorkdayCodeGenerator $workdayCodeGenerator,
        private readonly IBreedRepository $breedRepository,
        private readonly BreedMatcherService $breedMatcher,
        private readonly ICompanyContext $companyContext,
        private readonly CaravanTraceabilityService $traceabilityService,
        private readonly IBatchTypeRepository $batchTypeRepository
    ) {
    }

    /**
     * Import mapped rows from OCR into Caravan entities.
     * Each row is processed independently — failures don't roll back successful inserts.
     *
     * @param ImportCaravansDTO $dto
     * @return array{imported: int, skipped: int, errors: array<int, array{row: int, reason: string}>}
     */
    public function __invoke(ImportCaravansDTO $dto): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $processedCaravanIds = [];
        $activeCompanyId = $this->companyContext->getCompanyId();

        // Generar la jornada (Workday) real
        $workType = WorkType::from($dto->workType);
        $workDate = new \DateTimeImmutable();
        $code = $this->workdayCodeGenerator->generateForDate($workDate);
        
        $workday = new WorkdayEntity(
            id: null,
            code: $code,
            type: $workType,
            workDate: $workDate,
        );

        $savedWorkday = $this->workdayRepository->save($workday);

        $batchId = $dto->batchId;

        // Si no hay batch_id, pero el frontend envió farm_id y batch_name, creamos el lote en vuelo
        if (!$batchId && $dto->farmId && $dto->batchName) {
            $existingBatch = $this->batchRepository->findByNameAndFarmId($dto->batchName, $dto->farmId);
            
            if ($existingBatch) {
                $batchId = $existingBatch->getId();
            } else {
                $operationalType = $this->batchTypeRepository->findByCodeAndCompany('OPERATIONAL', $activeCompanyId);
                $newBatch = new BatchEntity(
                    id: null,
                    name: $dto->batchName,
                    farmId: $dto->farmId,
                    observaciones: null,
                    isActive: true,
                    batchTypeId: $operationalType?->getId()
                );
                $savedBatch = $this->batchRepository->save($newBatch);
                $batchId = $savedBatch->getId();
            }
        }

        // Cargar todas las razas en memoria
        $allBreeds = $this->breedRepository->getAll();

        foreach ($dto->rows as $index => $row) {
            try {
                $identificationRaw = trim((string)($row['identification'] ?? ''));

                if ($identificationRaw === '') {
                    // Silently skip rows that are completely empty to avoid OCR noise errors
                    $hasAnyData = array_filter($row, fn($val) => trim((string)$val) !== '');
                    if (empty($hasAnyData)) {
                        $skipped++;
                        continue;
                    }

                    $errors[] = ['row' => $index + 1, 'reason' => 'Missing identification field.'];
                    continue;
                }

                $identification = new CaravanNumber($identificationRaw);
                
                // 1. Intentar encontrar en la compañía actual
                $existingEntity = $this->repository->findByIdentification($identification);

                if ($existingEntity !== null) {
                    $this->updateCaravan($existingEntity, $row, $allBreeds, $batchId, $dto->emptyDestinationBatchId, $dto->serviceOrderId);
                    $imported++;
                    if ($existingEntity->getId()) {
                        $processedCaravanIds[] = $existingEntity->getId();
                    }
                    continue;
                }

                // 2. Intentar encontrar GLOBALMENTE (Transferencia)
                $globalEntity = $this->repository->findByIdentificationGlobal($identification);
                if ($globalEntity !== null) {
                    $oldCompanyId = $globalEntity->getCompanyId();
                    
                    $isEmpty = false;
                    if ($globalEntity->getSex() === \App\Core\Enums\AnimalSex::FEMALE) {
                        $diagnosisRaw = $row['diagnostico'] ?? $row['diagnstico'] ?? null;
                        if ($diagnosisRaw !== null) {
                            $isEmpty = str_contains(strtolower((string)$diagnosisRaw), 'vac');
                        } else {
                            $isEmpty = isset($row['is_empty']) ? filter_var($row['is_empty'], FILTER_VALIDATE_BOOLEAN) : false;
                        }
                    }
                    $assignedBatchId = ($isEmpty && $dto->emptyDestinationBatchId) ? $dto->emptyDestinationBatchId : $batchId;

                    // Crear nueva instancia de entidad para la nueva compañía (Transferencia)
                    $transferEntity = new CaravanEntity(
                        $globalEntity->getId(),
                        $globalEntity->getIdentification(),
                        $globalEntity->getTeeth(),
                        $globalEntity->getEntryWeight(),
                        $globalEntity->getExitWeight(),
                        $globalEntity->getBreedId(),
                        $globalEntity->getBreedName(),
                        $globalEntity->getColorId(),
                        $globalEntity->getColorName(),
                        $globalEntity->getSex(),
                        $globalEntity->getEntryDate(),
                        null,
                        $assignedBatchId,
                        $activeCompanyId,
                        null,
                        $globalEntity->getCurrentWeight(),
                        $globalEntity->getReproductiveDetails(),
                        $globalEntity->getGestations(),
                        $globalEntity->getLineage(),
                        $globalEntity->getRenspa(),
                        $globalEntity->getProviderId(),
                        $globalEntity->getProviderName(),
                        $globalEntity->getProvenance(),
                        $globalEntity->getCategoryId(),
                        $globalEntity->getCategoryCode(),
                        $globalEntity->getCategoryName(),
                        $globalEntity->getSubcategoryId(),
                        $globalEntity->getSubcategoryCode(),
                        $globalEntity->getSubcategoryName()
                    );

                    $this->updateCaravan($transferEntity, $row, $allBreeds, $assignedBatchId, $dto->emptyDestinationBatchId, $dto->serviceOrderId);
                    $savedTransfer = $this->repository->save($transferEntity);
                    
                    if ($oldCompanyId && $activeCompanyId) {
                        $this->traceabilityService->recordTransfer($savedTransfer, $oldCompanyId, $activeCompanyId);
                    }

                    $imported++;
                    if ($savedTransfer->getId()) {
                        $processedCaravanIds[] = $savedTransfer->getId();
                    }
                    continue;
                }

                // 3. Crear Nueva (Arrival)
                $category = isset($row['category']) && $row['category'] !== ''
                    ? CaravanValueParser::parseCategory((string) $row['category'])
                    : null;
                $teeth = CaravanValueParser::parseTeeth((string) ($row['teeth'] ?? '0'));
                $entryWeight = isset($row['entry_weight']) && $row['entry_weight'] !== ''
                    ? CaravanValueParser::parseWeight((string) $row['entry_weight'])
                    : null;
                
                $breedData = $this->resolveBreed($row, $allBreeds);
                $breed = $breedData['name'];
                $breedId = $breedData['id'];
                
                $sexRaw = $row['sex'] ?? '';
                $sex = CaravanValueParser::parseSex((string) $sexRaw, $category);

                $entryDate = null;
                if (isset($row['entry_date']) && $row['entry_date'] !== '') {
                    $parsedDate = CaravanValueParser::parseDate((string) $row['entry_date']);
                    if ($parsedDate) {
                        $entryDate = new \DateTime($parsedDate);
                    }
                }

                $isEmpty = false;
                if ($sex === \App\Core\Enums\AnimalSex::FEMALE && $category !== null) {
                    $diagnosisRaw = $row['diagnostico'] ?? $row['diagnstico'] ?? null;
                    if ($diagnosisRaw !== null) {
                        $normalizedDiag = strtolower(trim((string)$diagnosisRaw));
                        $isEmpty = str_contains($normalizedDiag, 'vac') || str_contains($normalizedDiag, 'empty') || $normalizedDiag === 'empty';
                    } else {
                        $isEmpty = isset($row['is_empty']) ? filter_var($row['is_empty'], FILTER_VALIDATE_BOOLEAN) : false;
                    }
                }

                $assignedBatchId = ($isEmpty && $dto->emptyDestinationBatchId) ? $dto->emptyDestinationBatchId : $batchId;

                $catId = null;
                if ($category !== null) {
                    $searchCode = strtoupper($category->value);
                    $codeMap = ['TERNERA' => 'TERNERO', 'VACA_VACIA' => 'VACA', 'VACA VACIA' => 'VACA'];
                    $searchCode = $codeMap[$searchCode] ?? $searchCode;
                    $catId = \App\Models\AnimalCategory::where('code', $searchCode)->value('id');
                }

                $entity = new CaravanEntity(
                    id: null,
                    identification: $identification,
                    teeth: $teeth,
                    entryWeight: $entryWeight,
                    exitWeight: null,
                    breedId: $breedId,
                    breedName: $breed,
                    colorId: null,
                    colorName: null,
                    sex: $sex,
                    entryDate: $entryDate,
                    createdAt: null,
                    batchId: $assignedBatchId,
                    companyId: $activeCompanyId,
                    batchName: null,
                    currentWeight: $entryWeight,
                    reproductiveDetails: null,
                    gestations: [],
                    lineage: null,
                    renspa: 'NO_DEFINIDO',
                    providerId: null,
                    providerName: null,
                    provenance: null,
                    categoryId: $catId
                );

                if ($sex === \App\Core\Enums\AnimalSex::FEMALE) {
                    $arrivalCategory = $category ?? AnimalCategory::VACA;
                    $entity->recordFemaleDetails(new FemaleReproductiveDetails($isEmpty, $arrivalCategory));
                    
                    if (!$isEmpty) {
                        $stageRaw = $row['gestational_stage'] ?? $row['estadio_estimado'] ?? $row['estadioestimado'] ?? null;
                        $monthsRaw = isset($row['gestation_months']) ? (float)$row['gestation_months'] : null;
                        [$stage, $months] = $this->resolveGestationDetails($stageRaw, $monthsRaw);
                        $startDate = $entryDate ? $entryDate->format('Y-m-d') : date('Y-m-d');
                        $entity->startNewGestation($startDate, $stage, $months, $dto->serviceOrderId);
                    }
                }

                $savedEntity = $this->repository->save($entity);
                
                // Trazabilidad inicial
                if ($activeCompanyId) {
                    $this->traceabilityService->recordInitialArrival($savedEntity, $activeCompanyId);
                }

                $imported++;
                if ($savedEntity->getId()) {
                    $processedCaravanIds[] = $savedEntity->getId();
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'row' => $index + 1,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        // Vincular animales a la jornada
        if (!empty($processedCaravanIds)) {
            $this->workdayRepository->attachCaravans($savedWorkday, $processedCaravanIds);
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'workday_code' => $savedWorkday->getCode(),
        ];
    }

    private function updateCaravan(
        CaravanEntity $entity,
        array $row,
        array &$allBreeds,
        ?int $batchId,
        ?int $emptyDestinationBatchId = null,
        ?int $serviceOrderId = null
    ): void {
        $category = null;
        $catId = $entity->getCategoryId();
        $subId = $entity->getSubcategoryId();

        if (isset($row['category']) && (string)$row['category'] !== '') {
            $category = CaravanValueParser::parseCategory((string)$row['category']);
            if ($category !== null) {
                $searchCode = strtoupper($category->value);
                $codeMap = ['TERNERA' => 'TERNERO', 'VACA_VACIA' => 'VACA', 'VACA VACIA' => 'VACA'];
                $searchCode = $codeMap[$searchCode] ?? $searchCode;
                $resolvedCatId = \App\Models\AnimalCategory::where('code', $searchCode)->value('id');
                if ($resolvedCatId) {
                    $catId = $resolvedCatId;
                }
            }
        }

        $teeth = $entity->getTeeth();
        if (isset($row['teeth']) && (string)$row['teeth'] !== '') {
            $teeth = CaravanValueParser::parseTeeth((string)$row['teeth']);
        }

        $entryWeight = $entity->getEntryWeight();
        if (isset($row['entry_weight']) && (string)$row['entry_weight'] !== '') {
            $entryWeight = CaravanValueParser::parseWeight((string)$row['entry_weight']) ?? $entryWeight;
        }

        $exitWeight = $entity->getExitWeight();
        if (isset($row['exit_weight']) && (string)$row['exit_weight'] !== '') {
            $exitWeight = CaravanValueParser::parseWeight((string)$row['exit_weight']) ?? $exitWeight;
        }

        $breed = $entity->getBreed();
        $breedId = $entity->getBreedId();
        $breedData = $this->resolveBreed($row, $allBreeds);
        if ($breedData['name']) {
            $breed = $breedData['name'];
            $breedId = $breedData['id'];
        }

        $sex = $entity->getSex();
        if (isset($row['sex']) && (string)$row['sex'] !== '') {
            $sex = CaravanValueParser::parseSex((string)$row['sex'], $category);
        }

        $entryDate = $entity->getEntryDate();
        if (isset($row['entry_date']) && (string)$row['entry_date'] !== '') {
            $parsedDate = CaravanValueParser::parseDate((string)$row['entry_date']);
            if ($parsedDate) {
                $entryDate = new \DateTime($parsedDate);
            }
        }

        $isEmpty = false;
        if ($sex === \App\Core\Enums\AnimalSex::FEMALE) {
            $currentDetails = $entity->getReproductiveDetails();
            $diagnosisRaw = $row['diagnostico'] ?? $row['diagnstico'] ?? null;
            if ($diagnosisRaw !== null) {
                $normalizedDiag = strtolower(trim((string)$diagnosisRaw));
                $isEmpty = str_contains($normalizedDiag, 'vac') || str_contains($normalizedDiag, 'empty') || $normalizedDiag === 'empty';
            } else {
                $isEmpty = isset($row['is_empty']) ? filter_var($row['is_empty'], FILTER_VALIDATE_BOOLEAN) : ($currentDetails?->isEmpty() ?? false);
            }
        }

        $assignedBatchId = ($isEmpty && $emptyDestinationBatchId) ? $emptyDestinationBatchId : $batchId;

        $entity->updateDetails($teeth, $entryWeight, $exitWeight, $sex, $entryDate, $assignedBatchId, $breedId, null, $catId, $subId);

        if ($sex === \App\Core\Enums\AnimalSex::FEMALE) {
            $currentDetails = $entity->getReproductiveDetails();
            $arrivalCategory = $currentDetails?->getArrivalCategory() ?? ($category ?? AnimalCategory::VACA);
            
            $entity->recordFemaleDetails(new FemaleReproductiveDetails($isEmpty, $arrivalCategory));

            // Gestation Automation: If empty and has active gestation, close it as SUCCESSFUL (calving)
            if ($isEmpty && $entity->hasActiveGestation()) {
                $endDate = $entryDate ? $entryDate->format('Y-m-d') : date('Y-m-d');
                $entity->getActiveGestation()->closeGestation(
                    true,
                    $endDate,
                    'Closed via empty gestation diagnosis on batch import.'
                );
            }

            // Gestation Automation: If pregnant and does not have active gestation, start one
            if (!$isEmpty) {
                if (!$entity->hasActiveGestation()) {
                    $startDate = $entryDate ? $entryDate->format('Y-m-d') : date('Y-m-d');
                    $stageRaw = $row['gestational_stage'] ?? $row['estadio_estimado'] ?? $row['estadioestimado'] ?? null;
                    $monthsRaw = isset($row['gestation_months']) ? (float)$row['gestation_months'] : null;
                    [$stage, $months] = $this->resolveGestationDetails($stageRaw, $monthsRaw);
                    $entity->startNewGestation($startDate, $stage, $months, $serviceOrderId);
                }
            }
        }

        $this->repository->save($entity);
    }

    private function resolveBreed(array $row, array &$allBreeds): array
    {
        $breed = null;
        $breedId = null;
        
        if (isset($row['breed']) && $row['breed'] !== '') {
            $parsedBreed = CaravanValueParser::parseBreed((string) $row['breed']);
            if ($parsedBreed !== null) {
                $matchedBreed = $this->breedMatcher->findBestMatch($parsedBreed, $allBreeds);
                
                if ($matchedBreed !== null) {
                    $breedId = $matchedBreed->getId();
                    $breed = $matchedBreed->getName();
                } else {
                    $newBreed = $this->breedRepository->findByNameOrCreate($parsedBreed);
                    $breedId = $newBreed->getId();
                    $breed = $newBreed->getName();
                    $allBreeds[] = $newBreed;
                }
            }
        }

        return ['id' => $breedId, 'name' => $breed];
    }

    /**
     * Resolve gestation stage and months bidirectionally.
     *
     * @param string|null $stageStr
     * @param float|null $months
     * @return array{0: \App\Core\Enums\GestationStage, 1: float}
     */
    private function resolveGestationDetails(?string $stageStr, ?float $months): array
    {
        if ($months !== null) {
            $stage = \App\Core\Enums\GestationStage::fromMonths($months);
            return [$stage, $months];
        }

        if ($stageStr !== null) {
            $normalizedStage = strtolower(trim($stageStr));
            if (str_contains($normalizedStage, 'cabeza') || str_contains($normalizedStage, 'head') || str_contains($normalizedStage, 'cobeza')) {
                $stage = \App\Core\Enums\GestationStage::HEAD;
            } elseif (str_contains($normalizedStage, 'cuerpo') || str_contains($normalizedStage, 'body')) {
                $stage = \App\Core\Enums\GestationStage::BODY;
            } elseif (str_contains($normalizedStage, 'cola') || str_contains($normalizedStage, 'tail')) {
                $stage = \App\Core\Enums\GestationStage::TAIL;
            } else {
                try {
                    $stage = \App\Core\Enums\GestationStage::from(strtoupper($stageStr));
                } catch (\ValueError) {
                    $stage = \App\Core\Enums\GestationStage::HEAD;
                }
            }
            return [$stage, $stage->toDefaultMonths()];
        }

        return [\App\Core\Enums\GestationStage::HEAD, 3.0];
    }
}
