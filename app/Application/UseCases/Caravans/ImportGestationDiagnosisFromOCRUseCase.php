<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\ImportOCRGestationDiagnosisDTO;
use App\Application\DTOs\RegisterGestationDiagnosisDTO;
use App\Core\Enums\GestationStage;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\ValueObjects\CaravanNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bridge Use Case: transforms OCR-extracted rows (with identification strings)
 * into individual RegisterGestationDiagnosisDTO calls (with caravan_id ints).
 *
 * This keeps the frontend simple — it sends data as-is from the OCR —
 * and concentrates all resolution logic in the backend.
 */
final class ImportGestationDiagnosisFromOCRUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly RegisterGestationDiagnosisUseCase $registerDiagnosis
    ) {
    }

    /**
     * Process OCR rows and delegate to RegisterGestationDiagnosisUseCase.
     *
     * @param ImportOCRGestationDiagnosisDTO $dto
     * @param int $companyId
     * @return array{processed: int, skipped: int, errors: array<int, array{row: int, identification: string, reason: string}>}
     */
    public function __invoke(ImportOCRGestationDiagnosisDTO $dto, int $companyId): array
    {
        $processed = 0;
        $skipped = 0;
        $errors = [];
        $diagnosisDate = $dto->diagnosisDate ?? date('Y-m-d');

        return DB::transaction(function () use ($dto, $companyId, $diagnosisDate, &$processed, &$skipped, &$errors) {

            foreach ($dto->rows as $index => $row) {
                $identificationRaw = trim((string)($row['identification'] ?? ''));

                // Skip completely empty rows (OCR noise)
                if ($identificationRaw === '') {
                    $hasAnyData = array_filter($row, fn($val) => is_string($val) && trim($val) !== '');
                    if (empty($hasAnyData)) {
                        $skipped++;
                        continue;
                    }

                    $errors[] = [
                        'row'            => $index + 1,
                        'identification' => '',
                        'reason'         => 'Missing identification field.',
                    ];
                    continue;
                }

                // 1. Resolve caravan by identification within the active company
                try {
                    $caravanNumber = new CaravanNumber($identificationRaw);
                    $caravan = $this->caravanRepository->findByIdentification($caravanNumber);
                } catch (\Throwable $e) {
                    $errors[] = [
                        'row'            => $index + 1,
                        'identification' => $identificationRaw,
                        'reason'         => "Invalid identification format: {$e->getMessage()}",
                    ];
                    continue;
                }

                if ($caravan === null) {
                    $errors[] = [
                        'row'            => $index + 1,
                        'identification' => $identificationRaw,
                        'reason'         => "Caravan '{$identificationRaw}' not found in the active company.",
                    ];
                    continue;
                }

                // 2. Parse diagnosis value
                $diagnosticoRaw = strtoupper(trim((string)($row['diagnostico'] ?? '')));
                $isPregnant = in_array($diagnosticoRaw, ['PREGNANT', 'PREÑADA', 'PRENADA'], true);

                // 3. Parse gestation stage (only relevant if pregnant)
                $gestationStage = null;
                if ($isPregnant) {
                    $stageRaw = strtolower(trim((string)($row['gestation_stage'] ?? $row['gestational_stage'] ?? $row['estadio_estimado'] ?? '')));
                    $gestationStage = $this->resolveStageString($stageRaw);
                }

                // 4. Build DTO and delegate to the existing Use Case
                try {
                    $diagnosisDto = new RegisterGestationDiagnosisDTO(
                        caravanId: $caravan->getId(),
                        serviceOrderId: $dto->serviceOrderId,
                        isPregnant: $isPregnant,
                        gestationStage: $gestationStage,
                        gestationMonths: null,
                        confirmedSireId: null,
                        diagnosisDate: $diagnosisDate,
                        emptyDestinationBatchId: $dto->emptyDestinationBatchId
                    );

                    ($this->registerDiagnosis)($diagnosisDto, $companyId);
                    $processed++;

                    Log::info("ImportGestationFromOCR: Processed caravan '{$identificationRaw}' → " . ($isPregnant ? 'PREGNANT' : 'EMPTY'));

                } catch (\Throwable $e) {
                    $errors[] = [
                        'row'            => $index + 1,
                        'identification' => $identificationRaw,
                        'reason'         => $e->getMessage(),
                    ];
                }
            }

            return [
                'processed' => $processed,
                'skipped'   => $skipped,
                'errors'    => $errors,
            ];
        });
    }

    /**
     * Resolve a stage string to a GestationStage enum value string.
     *
     * Accepts both normalized values (head, body, tail) and
     * Spanish labels (cabeza, cuerpo, cola).
     */
    private function resolveStageString(string $stage): ?string
    {
        if ($stage === '') {
            return null;
        }

        // Direct enum values
        if (in_array($stage, ['head', 'body', 'tail'], true)) {
            return $stage;
        }

        // Spanish label mapping
        $map = [
            'cabeza'  => 'head',
            'cobeza'  => 'head',
            'cuerpo'  => 'body',
            'cola'    => 'tail',
        ];

        return $map[$stage] ?? GestationStage::HEAD->value;
    }
}
