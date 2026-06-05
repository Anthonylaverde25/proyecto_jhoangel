<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Enums\GestationStage;
use App\Core\Enums\AnimalCategory;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\ValueObjects\CaravanNumber;
use App\Core\ValueObjects\FemaleReproductiveDetails;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class Rep01TemplateProcessor
{
    public function __construct(
        private readonly OCRServiceOrderResolver $serviceOrderResolver,
        private readonly ICaravanRepository $caravanRepository
    ) {
    }

    /**
     * Process Gestation Diagnosis template (REP-01) and persist records directly.
     *
     * @param array $analysisResult
     * @param int $companyId
     * @param UploadedFile $file
     * @return void
     */
    public function process(array $analysisResult, int $companyId, UploadedFile $file): void
    {
        $tables = $analysisResult['tables'] ?? [];
        if (empty($tables)) {
            Log::warning("Rep01TemplateProcessor (REP-01): No tables found to process.");
            return;
        }

        // 1. Resolve Service Order from the OCR metadata / first table or filename fallback
        $serviceOrder = $this->serviceOrderResolver->resolve($analysisResult, $file, $companyId);
        if ($serviceOrder) {
            Log::info("Rep01TemplateProcessor (REP-01): Resolved Service Order Code: {$serviceOrder->code} (ID: {$serviceOrder->id})");
        } else {
            Log::info("Rep01TemplateProcessor (REP-01): No Service Order resolved. Gestations will be created without linking an order.");
        }

        // 2. Find the data table containing the caravan records
        $dataTable = null;
        foreach ($tables as $table) {
            $headers = $table['headers'] ?? [];
            // Handle variations of 'caravana' header (e.g. 'caravana', 'caravanas')
            $hasCaravanHeader = false;
            foreach ($headers as $header) {
                if (in_array(str_replace('_', '', strtolower($header)), ['caravana', 'caravanas', 'tag', 'identification'])) {
                    $hasCaravanHeader = true;
                    break;
                }
            }
            if ($hasCaravanHeader) {
                $dataTable = $table;
                break;
            }
        }

        if ($dataTable === null) {
            Log::error("Rep01TemplateProcessor (REP-01): Table containing 'caravana' header not found in analysis result.");
            return;
        }

        DB::transaction(function () use ($dataTable, $companyId, $serviceOrder) {
            $diagnosisDate = date('Y-m-d');

            foreach ($dataTable['rows'] as $row) {
                // Find caravan identifier
                $caravanCode = null;
                foreach ($row as $key => $cell) {
                    $cleanKey = str_replace('_', '', strtolower($key));
                    if (in_array($cleanKey, ['caravana', 'caravanas', 'tag', 'identification'])) {
                        $caravanCode = $cell['value'] ?? null;
                        break;
                    }
                }

                if (!$caravanCode) {
                    continue;
                }

                // Resolve caravan as domain entity using the repository
                try {
                    $caravanNumber = new CaravanNumber(trim((string)$caravanCode));
                    $caravan = $this->caravanRepository->findByIdentification($caravanNumber);
                } catch (\Throwable $e) {
                    Log::warning("Rep01TemplateProcessor (REP-01): Invalid caravan identification format: '{$caravanCode}'. Skipping.");
                    continue;
                }

                if (!$caravan) {
                    Log::warning("Rep01TemplateProcessor (REP-01): Caravan '{$caravanCode}' not found in database. Skipping row.");
                    continue;
                }

                // Extract diagnosis value
                $diagnosticoText = '';
                foreach ($row as $key => $cell) {
                    $cleanKey = str_replace('_', '', strtolower($key));
                    if (in_array($cleanKey, ['diagnostico', 'diagnstico'])) {
                        $diagnosticoText = $cell['value'] ?? '';
                        break;
                    }
                }
                $normalizer = new \App\Application\Services\Rep01SelectionNormalizer();
                $diagnosisResult = $normalizer->normalizeDiagnosis($diagnosticoText);
                $isPregnant = $diagnosisResult['value'] === 'PREGNANT';
                $isEmpty = $diagnosisResult['value'] === 'EMPTY';

                // If neither is explicitly selected, default to isEmpty = true
                if (!$isPregnant && !$isEmpty) {
                    $isEmpty = true;
                }

                if ($isPregnant) {
                    // Extract gestation stage
                    $estadioText = '';
                    foreach ($row as $key => $cell) {
                        $cleanKey = str_replace('_', '', strtolower($key));
                        if (in_array($cleanKey, ['estadioestimado', 'estadio'])) {
                            $estadioText = $cell['value'] ?? '';
                            break;
                        }
                    }

                    $stageResult = $normalizer->normalizeGestationStage($estadioText);
                    $gestationStage = GestationStage::HEAD; // default fallback
                    if ($stageResult['value'] === 'body') {
                        $gestationStage = GestationStage::BODY;
                    } elseif ($stageResult['value'] === 'tail') {
                        $gestationStage = GestationStage::TAIL;
                    }

                    // Use Domain Entity methods for gestations and details
                    $caravan->recordFemaleDetails(new FemaleReproductiveDetails(
                        isEmpty: false,
                        arrivalCategory: $caravan->getCategory() ?? AnimalCategory::VACA
                    ));

                    $caravan->startNewGestation(
                        startDate: $diagnosisDate,
                        gestationStage: $gestationStage,
                        gestationMonths: $gestationStage->toDefaultMonths(),
                        serviceOrderId: $serviceOrder?->id
                    );

                    $this->caravanRepository->save($caravan);

                    Log::info("Rep01TemplateProcessor (REP-01): Registered PREGNANT (Stage: {$gestationStage->value}) for caravan ID {$caravan->getId()} ({$caravan->getIdentification()->getValue()})");

                } else {
                    // Use Domain Entity methods to record empty and close active gestations
                    $caravan->recordFemaleDetails(new FemaleReproductiveDetails(
                        isEmpty: true,
                        arrivalCategory: $caravan->getCategory() ?? AnimalCategory::VACA
                    ));

                    if ($caravan->hasActiveGestation()) {
                        $caravan->getActiveGestation()->closeGestation(
                            success: false,
                            endDate: $diagnosisDate,
                            notes: 'Closed via empty gestation diagnosis OCR (REP-01)'
                        );
                    }

                    $this->caravanRepository->save($caravan);

                    Log::info("Rep01TemplateProcessor (REP-01): Registered EMPTY for caravan ID {$caravan->getId()} ({$caravan->getIdentification()->getValue()})");
                }
            }
        });
    }
}
