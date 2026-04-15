<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\ImportCaravansDTO;
use App\Core\Entities\CaravanEntity;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Services\CaravanValueParser;
use App\Core\ValueObjects\CaravanNumber;

final class ImportCaravansUseCase
{
    public function __construct(
        private readonly ICaravanRepository $repository,
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
                    if (isset($row['breed']) && (string)$row['breed'] !== '') {
                        $breed = CaravanValueParser::parseBreed((string)$row['breed']) ?? $breed;
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

                    $existingEntity->updateDetails($category, $teeth, $entryWeight, $exitWeight, $breed, $sex, $entryDate);
                    $this->repository->save($existingEntity);
                    $imported++;
                    continue;
                }

                $category = isset($row['category']) && $row['category'] !== ''
                    ? CaravanValueParser::parseCategory((string) $row['category'])
                    : null;
                $teeth = CaravanValueParser::parseTeeth((string) ($row['teeth'] ?? '0'));
                $entryWeight = isset($row['entry_weight']) && $row['entry_weight'] !== ''
                    ? CaravanValueParser::parseWeight((string) $row['entry_weight'])
                    : null;
                $breed = isset($row['breed']) && $row['breed'] !== ''
                    ? CaravanValueParser::parseBreed((string) $row['breed'])
                    : null;
                
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
                    sex: $sex,
                    entryDate: $entryDate,
                    createdAt: null,
                );

                $this->repository->save($entity);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'row' => $index + 1,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
