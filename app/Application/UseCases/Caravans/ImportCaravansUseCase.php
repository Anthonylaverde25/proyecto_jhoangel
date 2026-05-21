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
                    $this->updateCaravan($existingEntity, $row, $allBreeds, $batchId);
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
                    
                    // Crear nueva instancia de entidad para la nueva compañía (Transferencia)
                    $transferEntity = new CaravanEntity(
                        $globalEntity->getId(),
                        $globalEntity->getIdentification(),
                        $globalEntity->getCategory(),
                        $globalEntity->getTeeth(),
                        $globalEntity->getEntryWeight(),
                        $globalEntity->getExitWeight(),
                        $globalEntity->getBreed(),
                        $globalEntity->getBreedId(),
                        $globalEntity->getSex(),
                        $globalEntity->getEntryDate(),
                        null,
                        $batchId,
                        $activeCompanyId
                    );

                    $this->updateCaravan($transferEntity, $row, $allBreeds, $batchId);
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

                $entity = new CaravanEntity(
                    id: null,
                    identification: $identification,
                    category: $category,
                    teeth: $teeth,
                    entryWeight: $entryWeight,
                    exitWeight: null,
                    breed: $breed,
                    breedId: $breedId,
                    sex: $sex,
                    entryDate: $entryDate,
                    createdAt: null,
                    batchId: $batchId,
                    companyId: $activeCompanyId
                );

                if ($sex === \App\Core\Enums\AnimalSex::FEMALE && $category !== null) {
                    $isEmpty = isset($row['is_empty']) ? filter_var($row['is_empty'], FILTER_VALIDATE_BOOLEAN) : true;
                    $entity->recordFemaleDetails(new FemaleReproductiveDetails($isEmpty, $category));
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

    private function updateCaravan(CaravanEntity $entity, array $row, array &$allBreeds, ?int $batchId): void
    {
        $category = $entity->getCategory();
        if (isset($row['category']) && (string)$row['category'] !== '') {
            $category = CaravanValueParser::parseCategory((string)$row['category']) ?? $category;
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

        $entity->updateDetails($category, $teeth, $entryWeight, $exitWeight, $breed, $sex, $entryDate, $batchId, $breedId);

        if ($sex === \App\Core\Enums\AnimalSex::FEMALE && $category !== null) {
            $currentDetails = $entity->getReproductiveDetails();
            $isEmpty = isset($row['is_empty']) ? filter_var($row['is_empty'], FILTER_VALIDATE_BOOLEAN) : ($currentDetails?->isEmpty() ?? true);
            $arrivalCategory = $currentDetails?->getArrivalCategory() ?? $category;
            
            $entity->recordFemaleDetails(new FemaleReproductiveDetails($isEmpty, $arrivalCategory));
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
}
