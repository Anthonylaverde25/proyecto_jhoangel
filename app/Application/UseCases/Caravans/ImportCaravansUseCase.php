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
use App\Core\Entities\BatchEntity;
use App\Core\Services\WorkdayCodeGenerator;
use App\Core\Interfaces\IBreedRepository;

final class ImportCaravansUseCase
{
    public function __construct(
        private readonly ICaravanRepository $repository,
        private readonly IWorkdayRepository $workdayRepository,
        private readonly IBatchRepository $batchRepository,
        private readonly WorkdayCodeGenerator $workdayCodeGenerator,
        private readonly IBreedRepository $breedRepository
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
                $newBatch = new BatchEntity(
                    id: null,
                    name: $dto->batchName,
                    farmId: $dto->farmId,
                    observaciones: null,
                    isActive: true
                );
                $savedBatch = $this->batchRepository->save($newBatch);
                $batchId = $savedBatch->getId();
            }
        }

        // Cargar todas las razas en memoria (Caché Anti N+1)
        $allBreeds = $this->breedRepository->getAll();
        $breedsCache = [];
        foreach ($allBreeds as $breedEntity) {
            $breedsCache[mb_strtolower($breedEntity->getName())] = $breedEntity->getId();
        }

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
                $existingEntity = $this->repository->findByIdentification($identification);

                if ($existingEntity !== null) {
                    $category = $existingEntity->getCategory();
                    if (isset($row['category']) && (string)$row['category'] !== '') {
                        $category = CaravanValueParser::parseCategory((string)$row['category']) ?? $category;
                    }

                    $teeth = $existingEntity->getTeeth();
                    if (isset($row['teeth']) && (string)$row['teeth'] !== '') {
                        $teeth = CaravanValueParser::parseTeeth((string)$row['teeth']);
                    }

                    $entryWeight = $existingEntity->getEntryWeight();
                    if (isset($row['entry_weight']) && (string)$row['entry_weight'] !== '') {
                        $entryWeight = CaravanValueParser::parseWeight((string)$row['entry_weight']) ?? $entryWeight;
                    }

                    $exitWeight = $existingEntity->getExitWeight();
                    if (isset($row['exit_weight']) && (string)$row['exit_weight'] !== '') {
                        $exitWeight = CaravanValueParser::parseWeight((string)$row['exit_weight']) ?? $exitWeight;
                    }

                    $breed = $existingEntity->getBreed();
                    $breedId = $existingEntity->getBreedId();
                    if (isset($row['breed']) && (string)$row['breed'] !== '') {
                        $parsedBreed = CaravanValueParser::parseBreed((string)$row['breed']);
                        if ($parsedBreed !== null) {
                            $breed = $parsedBreed;
                            $lowerBreed = mb_strtolower($parsedBreed);
                            if (isset($breedsCache[$lowerBreed])) {
                                $breedId = $breedsCache[$lowerBreed];
                            } else {
                                $newBreed = $this->breedRepository->findByNameOrCreate($parsedBreed);
                                $breedId = $newBreed->getId();
                                $breedsCache[$lowerBreed] = $breedId;
                            }
                        }
                    }

                    $sex = $existingEntity->getSex();
                    if (isset($row['sex']) && (string)$row['sex'] !== '') {
                        $sex = CaravanValueParser::parseSex((string)$row['sex'], $category);
                    }

                    $entryDate = $existingEntity->getEntryDate();
                    if (isset($row['entry_date']) && (string)$row['entry_date'] !== '') {
                        $parsedDate = CaravanValueParser::parseDate((string)$row['entry_date']);
                        if ($parsedDate) {
                            $entryDate = new \DateTime($parsedDate);
                        }
                    }

                    $existingEntity->updateDetails($category, $teeth, $entryWeight, $exitWeight, $breed, $sex, $entryDate, $batchId, $breedId);
                    $this->repository->save($existingEntity);
                    $imported++;
                    if ($existingEntity->getId()) {
                        $processedCaravanIds[] = $existingEntity->getId();
                    }
                    continue;
                }

                $category = isset($row['category']) && $row['category'] !== ''
                    ? CaravanValueParser::parseCategory((string) $row['category'])
                    : null;
                $teeth = CaravanValueParser::parseTeeth((string) ($row['teeth'] ?? '0'));
                $entryWeight = isset($row['entry_weight']) && $row['entry_weight'] !== ''
                    ? CaravanValueParser::parseWeight((string) $row['entry_weight'])
                    : null;
                $breed = null;
                $breedId = null;
                if (isset($row['breed']) && $row['breed'] !== '') {
                    $parsedBreed = CaravanValueParser::parseBreed((string) $row['breed']);
                    if ($parsedBreed !== null) {
                        $breed = $parsedBreed;
                        $lowerBreed = mb_strtolower($parsedBreed);
                        if (isset($breedsCache[$lowerBreed])) {
                            $breedId = $breedsCache[$lowerBreed];
                        } else {
                            $newBreed = $this->breedRepository->findByNameOrCreate($parsedBreed);
                            $breedId = $newBreed->getId();
                            $breedsCache[$lowerBreed] = $breedId;
                        }
                    }
                }
                
                $sexRaw = $row['sex'] ?? '';
                $sex = CaravanValueParser::parseSex((string) $sexRaw, $category);

                if ($sex === 'N/D') {
                    throw new \App\Core\Exceptions\DomainException("El campo 'sexo' es obligatorio para nuevas caravanas y no pudo ser inferido.");
                }

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
                );

                $savedEntity = $this->repository->save($entity);
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
}
