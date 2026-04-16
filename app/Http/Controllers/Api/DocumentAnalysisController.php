<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\FieldMappings\FieldMappingUseCases;
use App\Infrastructure\OCR\OCRProviderFactory;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DocumentAnalysisController extends Controller
{
    public function __construct(
        private readonly FieldMappingUseCases $fieldMappings,
        private readonly \App\Application\Services\OCRNormalizationService $normalizationService,
        private readonly \App\Core\Services\WorkdayCodeGenerator $workdayCodeGenerator
    ) {
    }

    /**
     * Analyze a document using the configured OCR provider (Azure or Google).
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(Request $request)
    {
        // Diagnostic for GET requests
        if ($request->isMethod('get')) {
            return response()->json([
                'status' => 'online',
                'message' => 'Document Analysis Service ready',
                'default_driver' => config('services.ocr.driver'),
                'usage' => [
                    'method' => 'POST',
                    'sample_curl' => 'curl -X POST -F "document=@path/to/file.pdf" -F "provider=google" ' . url($request->path())
                ]
            ]);
        }

        $request->validate([
            'document' => 'required|file|mimes:pdf,png,jpg,jpeg,tiff|max:20480', // Max 20MB
            'provider' => 'nullable|string|in:azure,google',
        ]);

        try {
            $file = $request->file('document');
            $requestedProvider = $request->input('provider');
            
            // 1. Resolve Provider and Digitalize
            $ocrProvider = OCRProviderFactory::make($requestedProvider);
            $extractedData = $ocrProvider->analyze($file);

            // 2. Resolve field mappings (synonym system)
            $targetModel = $request->input('target_model', 'caravans');

            foreach ($extractedData as &$table) {
                $resolution = ($this->fieldMappings->resolve)($table['headers'], $targetModel);
                $table['field_mapping'] = $resolution['mapped'];
                $table['unresolved_headers'] = $resolution['unresolved'];

                // Re-map rows using resolved field names (keeping value/confidence structure)
                $table['mapped_rows'] = array_map(function (array $row) use ($resolution): array {
                    $mappedRow = [];
                    foreach ($row as $header => $data) {
                        $targetField = $resolution['mapped'][$header] ?? $header;
                        
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
                }, $table['rows']);
            }
            unset($table);

            return response()->json([
                'status' => 'success',
                'provider' => $requestedProvider ?? config('services.ocr.driver'),
                'suggested_workday_code' => $this->workdayCodeGenerator->generateForDate(new \DateTime()),
                'document_info' => [
                    'pages' => count($extractedData),
                ],
                'data' => $extractedData,
            ]);

        } catch (\Exception $e) {
            Log::error('Document Analysis Exception', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Analysis failed: ' . $e->getMessage()], 500);
        }
    }
}
