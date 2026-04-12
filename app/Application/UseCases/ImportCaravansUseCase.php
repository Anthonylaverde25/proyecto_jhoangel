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

                // Parse OCR values through CaravanValueParser
                $teeth = CaravanValueParser::parseTeeth($row['teeth'] ?? '0');
                $entryWeight = isset($row['entry_weight']) && $row['entry_weight'] !== ''
                    ? CaravanValueParser::parseWeight($row['entry_weight'])
                    : null;
                $exitWeight = isset($row['exit_weight']) && $row['exit_weight'] !== ''
                    ? CaravanValueParser::parseWeight($row['exit_weight'])
                    : null;
                $entryDate = isset($row['entry_date']) && $row['entry_date'] !== ''
                    ? CaravanValueParser::parseDate($row['entry_date'])
                    : null;

                if ($existingEntity !== null) {
                    // Actualización (Upsert)
                    $existingEntity->updateDetails(
                        null, // category no viene en el row usualmente, o mantener null
                        $teeth,
                        $entryWeight,
                        $exitWeight,
                        $row['breed'] ?? null,
                        $row['sex'] ?? null
                    );
                    $this->repository->save($existingEntity);
                    $imported++;
                    continue;
                }

                $entity = new CaravanEntity(
                    id: null,
                    identification: $identification,
                    category: null,
                    teeth: $teeth,
                    entryWeight: $entryWeight,
                    exitWeight: null,
                    breed: $row['breed'] ?? null,
                    sex: $row['sex'] ?? null,
                    entryDate: $entryDate,
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
