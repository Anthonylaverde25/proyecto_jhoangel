<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

use App\Application\Services\WorkTemplateIdentificationService;
use App\Application\Services\OCRTemplateDataProcessor;
use App\Core\Services\WorkdayCodeGenerator;
use App\Infrastructure\OCR\OCRProviderFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

final class IdentifyWorkTemplateUseCase
{
    public function __construct(
        private readonly WorkTemplateIdentificationService $identificationService,
        private readonly OCRTemplateDataProcessor $templateDataProcessor,
        private readonly WorkdayCodeGenerator $workdayCodeGenerator,
        private readonly \App\Application\UseCases\FieldMappings\FieldMappingUseCases $fieldMappings,
        private readonly \App\Application\Services\OCRNormalizationService $normalizationService
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
        Log::info("Datos desestructurados de la imagen (OCR)", [
            'tables'   => $analysisResult['tables']   ?? [],
            'metadata' => $analysisResult['metadata'] ?? []
        ]);

        // 2. Delegate template identification and context resolution to the application service
        $resolution = $this->identificationService->identify($analysisResult, $companyId, $file);

        Log::info("Resultado de la Identificación de Plantilla", [
            'plantilla_detectada' => $resolution['identified_template'] ? [
                'id'    => $resolution['identified_template']->getId(),
                'code'  => $resolution['identified_template']->getCode(),
                'title' => $resolution['identified_template']->getTitle(),
            ] : 'Ninguna coincidencia',
            'contexto_resuelto'   => $resolution['context']
        ]);

        // 3. Map and normalize tables columns/rows for the frontend interactive table edit
        $tables = $analysisResult['tables'] ?? [];
        $targetModel = 'caravans';

        foreach ($tables as &$table) {
            $fieldRes = ($this->fieldMappings->resolve)($table['headers'] ?? [], $targetModel);
            $table['field_mapping'] = $fieldRes['mapped'];
            $table['unresolved_headers'] = $fieldRes['unresolved'];

            // Re-map rows using resolved field names (keeping value/confidence structure)
            $table['mapped_rows'] = array_map(function (array $row) use ($fieldRes): array {
                $mappedRow = [];
                foreach ($row as $header => $data) {
                    $targetField = $fieldRes['mapped'][$header] ?? $header;
                    
                    // Normalize the value based on the target field
                    $normalizedValue = $this->normalizationService->normalize(
                        (string)($data['value'] ?? ''), 
                        $targetField
                    );

                    $mappedRow[$targetField] = [
                        'value' => $normalizedValue,
                        'confidence' => $data['confidence'] ?? 1.0
                    ];
                }
                return $mappedRow;
            }, $table['rows'] ?? []);
        }
        unset($table);

        return [
            'identified_template'    => $resolution['identified_template'],
            'context'                => $resolution['context'],
            'suggested_workday_code' => $this->workdayCodeGenerator->generateForDate(new \DateTime()),
            'data'                   => $tables,
        ];
    }
}
