<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Enums\GestationStage;
use App\Core\Enums\AnimalCategory;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\FemaleCaravanDetail;
use App\Models\ServiceOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class OCRTemplateDataProcessor
{
    /**
     * Process and persist OCR analysis results based on the template code.
     *
     * @param string $templateCode
     * @param array $analysisResult
     * @param int $companyId
     * @param UploadedFile $file
     * @return void
     */
    public function process(string $templateCode, array $analysisResult, int $companyId, UploadedFile $file): void
    {
        Log::info("OCRTemplateDataProcessor: Processing data for template code {$templateCode}");

        switch (strtoupper($templateCode)) {
            case 'REP-01':
                $this->processRep01($analysisResult, $companyId, $file);
                break;
            default:
                Log::warning("OCRTemplateDataProcessor: No processing logic registered for template: {$templateCode}");
                break;
        }
    }

    /**
     * Process Gestation Diagnosis template (REP-01) and persist records directly.
     *
     * @param array $analysisResult
     * @param int $companyId
     * @param UploadedFile $file
     * @return void
     */
    private function processRep01(array $analysisResult, int $companyId, UploadedFile $file): void
    {
        $tables = $analysisResult['tables'] ?? [];
        if (empty($tables)) {
            Log::warning("OCRTemplateDataProcessor (REP-01): No tables found to process.");
            return;
        }

        // 1. Resolve Service Order from the OCR metadata / first table or filename fallback
        $serviceOrder = $this->resolveServiceOrder($analysisResult, $file, $companyId);
        if ($serviceOrder) {
            Log::info("OCRTemplateDataProcessor (REP-01): Resolved Service Order Code: {$serviceOrder->code} (ID: {$serviceOrder->id})");
        } else {
            Log::info("OCRTemplateDataProcessor (REP-01): No Service Order resolved. Gestations will be created without linking an order.");
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
            Log::error("OCRTemplateDataProcessor (REP-01): Table containing 'caravana' header not found in analysis result.");
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

                // Resolve caravan in DB
                $caravan = Caravan::where('identification', trim((string)$caravanCode))
                    ->where('company_id', $companyId)
                    ->first();

                if (!$caravan) {
                    Log::warning("OCRTemplateDataProcessor (REP-01): Caravan '{$caravanCode}' not found in database. Skipping row.");
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

                $isPregnant = str_contains($diagnosticoText, 'Preñada :selected:');
                $isEmpty = str_contains($diagnosticoText, 'Vacía :selected:');

                // If neither is explicitly selected, default to isEmpty = true if "Vacía" text is present
                if (!$isPregnant && !$isEmpty) {
                    // Fallback to checking string presence or default empty
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

                    $gestationStage = GestationStage::HEAD; // default fallback
                    if (str_contains($estadioText, 'Cabeza :selected:')) {
                        $gestationStage = GestationStage::HEAD;
                    } elseif (str_contains($estadioText, 'Cuerpo :selected:')) {
                        $gestationStage = GestationStage::BODY;
                    } elseif (str_contains($estadioText, 'Cola :selected:')) {
                        $gestationStage = GestationStage::TAIL;
                    }

                    // 1. Close active gestations for this caravan
                    CaravanGestation::where('caravan_id', $caravan->id)
                        ->where('is_current', true)
                        ->update([
                            'is_current' => false,
                            'end_date'   => $diagnosisDate,
                            'success'    => false,
                            'notes'      => 'Closed automatically by new gestation diagnosis OCR'
                        ]);

                    // 2. Create new gestation record
                    CaravanGestation::create([
                        'caravan_id'         => $caravan->id,
                        'start_date'         => $diagnosisDate,
                        'is_current'         => true,
                        'gestation_stage'    => $gestationStage,
                        'gestation_months'   => $gestationStage->toDefaultMonths(),
                        'service_order_id'   => $serviceOrder?->id,
                        'notes'              => 'Registered via Work Template OCR (REP-01)'
                    ]);

                    // 3. Mark female detail as pregnant (is_empty = false)
                    FemaleCaravanDetail::updateOrCreate(
                        ['caravan_id' => $caravan->id],
                        [
                            'is_empty'         => false,
                            'arrival_category' => $caravan->category ?? AnimalCategory::VACA
                        ]
                    );

                    Log::info("OCRTemplateDataProcessor (REP-01): Registered PREGNANT (Stage: {$gestationStage->value}) for caravan ID {$caravan->id} ({$caravan->identification})");

                } else {
                    // 1. Close active gestations (since animal is empty)
                    CaravanGestation::where('caravan_id', $caravan->id)
                        ->where('is_current', true)
                        ->update([
                            'is_current' => false,
                            'end_date'   => $diagnosisDate,
                            'success'    => false,
                            'notes'      => 'Closed via empty gestation diagnosis OCR (REP-01)'
                        ]);

                    // 2. Mark female detail as empty (is_empty = true)
                    FemaleCaravanDetail::updateOrCreate(
                        ['caravan_id' => $caravan->id],
                        [
                            'is_empty'         => true,
                            'arrival_category' => $caravan->category ?? AnimalCategory::VACA
                        ]
                    );

                    Log::info("OCRTemplateDataProcessor (REP-01): Registered EMPTY for caravan ID {$caravan->id} ({$caravan->identification})");
                }
            }
        });
    }

    /**
     * Resolve the Service Order associated with this file / analysis result.
     *
     * @param array $analysisResult
     * @param UploadedFile $file
     * @param int $companyId
     * @return ServiceOrder|null
     */
    private function resolveServiceOrder(array $analysisResult, UploadedFile $file, int $companyId): ?ServiceOrder
    {
        $serviceOrderCode = null;

        // 1. Search in KVPs metadata
        $metadata = $analysisResult['metadata'] ?? [];
        foreach ($metadata as $key => $value) {
            $cleanKey = str_replace('_', '', strtolower($key));
            if ($cleanKey === 'serviceorder') {
                $serviceOrderCode = trim((string)$value);
                break;
            }
        }

        // 2. Search in first table column headers
        if (!$serviceOrderCode && !empty($analysisResult['tables'])) {
            $firstTable = $analysisResult['tables'][0];
            $soColumn = null;
            foreach ($firstTable['headers'] ?? [] as $h) {
                if (str_replace('_', '', strtolower($h)) === 'serviceorder') {
                    $soColumn = $h;
                    break;
                }
            }

            if ($soColumn && !empty($firstTable['rows'])) {
                $serviceOrderCode = trim((string)($firstTable['rows'][0][$soColumn]['value'] ?? ''));
            }
        }

        // 3. Fallback: Search in the filename
        if (!$serviceOrderCode) {
            $filename = $file->getClientOriginalName();
            if (preg_match('/(SO-[A-Za-z0-9-]+)/', $filename, $matches)) {
                $serviceOrderCode = $matches[1];
            }
        }

        if ($serviceOrderCode) {
            return ServiceOrder::where('code', $serviceOrderCode)
                ->where('company_id', $companyId)
                ->first();
        }

        return null;
    }
}
