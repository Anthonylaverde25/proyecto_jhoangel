<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

use App\Application\Services\WorkTemplateIdentificationService;
use App\Application\Services\OCRTemplateDataProcessor;
use App\Application\Services\Rep01SelectionNormalizer;
use App\Core\Services\WorkdayCodeGenerator;
use App\Infrastructure\OCR\OCRProviderFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

final class IdentifyWorkTemplateUseCase
{
    public function __construct(
        private readonly WorkTemplateIdentificationService $identificationService,
        private readonly OCRTemplateDataProcessor $templateDataProcessor, // < --- de momento no se esta usando. no borrar la logica
        private readonly WorkdayCodeGenerator $workdayCodeGenerator,
        private readonly \App\Application\UseCases\FieldMappings\FieldMappingUseCases $fieldMappings,
        private readonly \App\Application\Services\OCRNormalizationService $normalizationService,
        private readonly Rep01SelectionNormalizer $selectionNormalizer
    ) {
    }

    /**
     * Identify a work template from a document using OCR.
     *
     * @param UploadedFile $file
     * @param int $companyId
     * @param string|null $providerName
     * @return array
     */
    public function __invoke(UploadedFile $file, int $companyId, ?string $providerName = null): array
    {
        // 1. Resolve OCR Provider and Analyze Document
        // por defecto el provedor de servicio es azure 
        $ocrProvider = OCRProviderFactory::make($providerName);
        $analysisResult = $ocrProvider->analyze($file);

        // ─── LOG PARA VER LOS DATOS DESESTRUCTURADOS ───
        // Log::info("Datos desestructurados de la imagen (OCR)", [
        //     'tables'   => $analysisResult['tables']   ?? [],
        //     'metadata' => $analysisResult['metadata'] ?? []
        // ]);

        // 2. Delegate template identification and context resolution to the application service
        $resolution = $this->identificationService->identify($analysisResult, $companyId, $file);

        // Log::info("Resultado de la Identificación de Plantilla", [
        //     'plantilla_detectada' => $resolution['identified_template'] ? [
        //         'id'    => $resolution['identified_template']->getId(),
        //         'code'  => $resolution['identified_template']->getCode(),
        //         'title' => $resolution['identified_template']->getTitle(),
        //     ] : 'Ninguna coincidencia',
        //     'contexto_resuelto'   => $resolution['context']
        // ]);

        // 3. Map and normalize tables columns/rows for the frontend interactive table edit
        $tables = $analysisResult['tables'] ?? [];
        $targetModel = 'caravans';

        $identifiedTemplate = $resolution['identified_template'];
        $isRep01 = $identifiedTemplate && strtoupper($identifiedTemplate->getCode()) === 'REP-01';

        foreach ($tables as &$table) {
            $fieldRes = ($this->fieldMappings->resolve)($table['headers'] ?? [], $targetModel);

            // Handle canonical mappings for REP-01 template to align with frontend/schema expectations
            if ($isRep01) {
                $mapped = $fieldRes['mapped'];
                $unresolved = [];

                foreach ($table['headers'] ?? [] as $header) {
                    if ($this->selectionNormalizer->isCaravanaField($header)) {
                        $mapped[$header] = 'identification';
                    } elseif ($this->selectionNormalizer->isDiagnosisField($header)) {
                        $mapped[$header] = 'diagnosis';
                    } elseif ($this->selectionNormalizer->isGestationStageField($header)) {
                        $mapped[$header] = 'gestational_stage';
                    } elseif ($this->selectionNormalizer->isCategoryField($header)) {
                        $mapped[$header] = 'category';
                    } elseif ($this->selectionNormalizer->isObservationsField($header)) {
                        $mapped[$header] = 'observations';
                    } else {
                        if (isset($fieldRes['mapped'][$header])) {
                            $mapped[$header] = $fieldRes['mapped'][$header];
                        } else {
                            $unresolved[] = $header;
                        }
                    }
                }
                $table['field_mapping'] = $mapped;
                $table['unresolved_headers'] = $unresolved;
            } else {
                $table['field_mapping'] = $fieldRes['mapped'];
                $table['unresolved_headers'] = $fieldRes['unresolved'];
            }

            $activeMapping = $table['field_mapping'];

            // Re-map rows using resolved field names (keeping value/confidence structure)
            $table['mapped_rows'] = array_map(function (array $row) use ($activeMapping, $isRep01): array {
                $mappedRow = [];
                foreach ($row as $header => $data) {
                    $targetField = $activeMapping[$header] ?? $header;
                    $rawValue = (string)($data['value'] ?? '');
                    $confidence = $data['confidence'] ?? 1.0;

                    // REP-01 Selection Field Post-Processing
                    if ($isRep01 && $this->selectionNormalizer->hasSelectionMarkers($rawValue)) {
                        if ($targetField === 'diagnosis') {
                            $result = $this->selectionNormalizer->normalizeDiagnosis($rawValue);
                            $mappedRow[$targetField] = [
                                'value'      => $result['value'] ?? '',
                                'confidence' => $result['confidence'],
                            ];
                            continue;
                        }

                        if ($targetField === 'gestational_stage') {
                            $result = $this->selectionNormalizer->normalizeGestationStage($rawValue);
                            $mappedRow[$targetField] = [
                                'value'      => $result['value'] ?? '',
                                'confidence' => $result['confidence'],
                            ];
                            continue;
                        }
                    }

                    // Generic normalization for all other fields
                    $normalizedValue = $this->normalizationService->normalize($rawValue, $targetField);

                    $mappedRow[$targetField] = [
                        'value' => $normalizedValue,
                        'confidence' => $confidence,
                    ];
                }
                return $mappedRow;
            }, $table['rows'] ?? []);
        }
        unset($table);

        return [
            'identified_template'    => $identifiedTemplate,
            'context'                => $resolution['context'],
            'suggested_workday_code' => $this->workdayCodeGenerator->generateForDate(new \DateTime()),
            'data'                   => $tables,
        ];
    }
}
