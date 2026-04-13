<?php

declare(strict_types=1);

namespace App\Application\UseCases;

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
                $identificationRaw = trim($row['identification'] ?? '');

                if ($identificationRaw === '') {
                    $errors[] = ['row' => $index + 1, 'reason' => 'Missing identification field.'];
                    continue;
                }

                $identification = new CaravanNumber($identificationRaw);
                $existingEntity = $this->repository->findByIdentification($identification);

                if ($existingEntity !== null) {
                    // ESTRATEGIA DE PRESERVACIÓN (Merge): Solo actualizar si la columna está presente
                    $category = array_key_exists('category', $row) && $row['category'] !== ''
                        ? CaravanValueParser::parseCategory($row['category'])
                        : $existingEntity->getCategory();

                    $teeth = array_key_exists('teeth', $row)
                        ? CaravanValueParser::parseTeeth($row['teeth'] ?? '0')
                        : $existingEntity->getTeeth();

                    $entryWeight = array_key_exists('entry_weight', $row) && $row['entry_weight'] !== ''
                        ? CaravanValueParser::parseWeight($row['entry_weight'])
                        : $existingEntity->getEntryWeight();

                    $exitWeight = array_key_exists('exit_weight', $row) && $row['exit_weight'] !== ''
                        ? CaravanValueParser::parseWeight($row['exit_weight'])
                        : $existingEntity->getExitWeight();

                    $breed = array_key_exists('breed', $row) 
                        ? ($row['breed'] ?: null) 
                        : $existingEntity->getBreed();

                    $sex = array_key_exists('sex', $row)
                        ? ($row['sex'] ?: null)
                        : $existingEntity->getSex();

                    $existingEntity->updateDetails($category, $teeth, $entryWeight, $exitWeight, $breed, $sex);
                    $this->repository->save($existingEntity);
                    $imported++;
                    continue;
                }

                // Lógica de CREACIÓN (Original con parseo estándar)
                $category = isset($row['category']) && $row['category'] !== ''
                    ? CaravanValueParser::parseCategory($row['category'])
                    : null;
                $teeth = CaravanValueParser::parseTeeth($row['teeth'] ?? '0');
                $entryWeight = isset($row['entry_weight']) && $row['entry_weight'] !== ''
                    ? CaravanValueParser::parseWeight($row['entry_weight'])
                    : null;

                $entity = new CaravanEntity(
                    id: null,
                    identification: $identification,
                    category: $category,
                    teeth: $teeth,
                    entryWeight: $entryWeight,
                    exitWeight: null,
                    breed: $row['breed'] ?? null,
                    sex: $row['sex'] ?? null,
                    createdAt: null, // Automanaged by Laravel/Infrastructure
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
