<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\ResolveFieldMappingsUseCase;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AzureDocumentController extends Controller
{
    public function __construct(
        private readonly ResolveFieldMappingsUseCase $resolveFieldMappings
    ) {
    }

    /**
     * Analyze a document using Azure Document Intelligence Layout model.
     * This controller implements a simple polling mechanism for testing.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(Request $request)
    {
        // 0. Diagnostic for GET requests
        if ($request->isMethod('get')) {
            $apiKey = config('services.azure.key');
            $endpoint = config('services.azure.endpoint');

            return response()->json([
                'status' => 'online',
                'message' => 'Azure Document Intelligence Endpoint ready',
                'configuration' => [
                    'endpoint_configured' => !empty($endpoint),
                    'key_configured' => !empty($apiKey),
                ],
                'usage' => [
                    'method' => 'POST',
                    'sample_curl' => 'curl -X POST -F "document=@path/to/file.pdf" ' . url($request->path())
                ]
            ]);
        }

        $request->validate([
            'document' => 'required|file|mimes:pdf,png,jpg,jpeg,tiff|max:20480', // Max 20MB
        ]);

        $apiKey = config('services.azure.key');
        $endpoint = rtrim(config('services.azure.endpoint'), '/');

        if (!$apiKey || !$endpoint) {
            return response()->json(['error' => 'Azure Document Intelligence credentials not configured.'], 500);
        }

        try {
            $file = $request->file('document');
            $fileContent = file_get_contents($file->getRealPath());

            // 1. Submit the document to Azure (Layout Model)
            $response = Http::withHeaders([
                'Ocp-Apim-Subscription-Key' => $apiKey,
                'Content-Type' => $file->getMimeType(),
            ])->withBody($fileContent, $file->getMimeType())
                ->post("{$endpoint}/documentintelligence/documentModels/prebuilt-layout:analyze?api-version=2024-11-30");

            if ($response->failed()) {
                Log::error('Azure API Submission Failed', ['details' => $response->json()]);
                return response()->json([
                    'error' => 'Failed to submit document to Azure.',
                    'details' => $response->json()
                ], $response->status());
            }

            Log::info('Azure API Submission Success', ['details' => $response->json()]);

            // The API returns 202 Accepted and the result URL in the Operation-Location header
            // Get del resultado en funcion del Operation-Location
            $operationUrl = $response->header('Operation-Location');
            // Log::info('Azure API Operation URL', ['details' => $operationUrl]);

            if (!$operationUrl) {
                return response()->json(['error' => 'Operation-Location header not found.'], 500);
            }

            // 2. Poll for the result
            $status = 'running';
            $maxAttempts = 15; // Max 30 seconds (2s * 15)
            $attempts = 0;
            $resultResponse = null;

            while (in_array($status, ['running', 'notStarted']) && $attempts < $maxAttempts) {
                sleep(2);
                $resultResponse = Http::withHeaders([
                    'Ocp-Apim-Subscription-Key' => $apiKey
                ])->get($operationUrl);

                // Aqui se puede ver el resultado de la peticion
                Log::info('Azure API Result Response', ['details' => $resultResponse->json()]);

                $status = $resultResponse->json('status');
                $attempts++;
            }

            if ($status !== 'succeeded') {
                return response()->json([
                    'error' => 'Analysis did not complete in time or failed.',
                    'status' => $status,
                    'details' => $resultResponse ? $resultResponse->json() : null
                ], 500);
            }

            // 3. Process results and extract tables/headers
            $analyzeResult = $resultResponse->json('analyzeResult');
            // Log::info('Azure API Analyze Result', ['details' => $analyzeResult]);
            Log::info('TABLES', ['tables' => $analyzeResult['tables'] ?? []]);
            $extractedData = $this->parseTables($analyzeResult['tables'] ?? []);

            // 4. Resolve field mappings (synonym system)
            $targetModel = $request->input('target_model', 'caravans');

            foreach ($extractedData as &$table) {
                $resolution = ($this->resolveFieldMappings)($table['headers'], $targetModel);
                $table['field_mapping'] = $resolution['mapped'];
                $table['unresolved_headers'] = $resolution['unresolved'];

                // Re-map rows using resolved field names
                $table['mapped_rows'] = array_map(function (array $row) use ($resolution): array {
                    $mappedRow = [];
                    foreach ($row as $header => $value) {
                        $targetField = $resolution['mapped'][$header] ?? $header;
                        $mappedRow[$targetField] = $value;
                    }
                    return $mappedRow;
                }, $table['rows']);
            }
            unset($table);

            return response()->json([
                'status' => 'success',
                'raw_status' => $status,
                'document_info' => [
                    'pages' => count($analyzeResult['pages'] ?? []),
                    'model_id' => $analyzeResult['modelId'] ?? 'unknown',
                ],
                'data' => $extractedData,
            ]);

        } catch (\Exception $e) {
            Log::error('Azure Analysis Exception', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Parse tables and extract rows as objects using column headers.
     *
     * @param array $tables
     * @return array
     */
    private function parseTables(array $tables): array
    {
        $parsedTables = [];

        foreach ($tables as $index => $table) {
            $parsedTable = [
                'table_id' => $index,
                'row_count' => $table['rowCount'],
                'column_count' => $table['columnCount'],
                'rows' => [],
                'headers' => []
            ];

            // 1. Identify headers (usually rows with kind "columnHeader")
            $headers = [];
            foreach ($table['cells'] as $cell) {
                if (($cell['kind'] ?? '') === 'columnHeader' || ($cell['rowIndex'] === 0)) {
                    $headers[$cell['columnIndex']] = $this->sanitizeHeader($cell['content']);
                }
            }
            $parsedTable['headers'] = array_values($headers);

            // 2. Identify data rows
            $rows = [];
            foreach ($table['cells'] as $cell) {
                // Skip if it's a header cell (already processed)
                if (($cell['kind'] ?? '') === 'columnHeader' || ($cell['rowIndex'] === 0)) {
                    continue;
                }

                $rowIndex = $cell['rowIndex'];
                $columnIndex = $cell['columnIndex'];
                $headerName = $headers[$columnIndex] ?? "column_{$columnIndex}";

                $rows[$rowIndex][$headerName] = $cell['content'];
            }

            $parsedTable['rows'] = array_values($rows);
            $parsedTables[] = $parsedTable;
        }

        return $parsedTables;
    }

    /**
     * Sanitize header names for better JSON structure.
     *
     * @param string $content
     * @return string
     */
    private function sanitizeHeader(string $content): string
    {
        // Remove special characters, normalize spaces to underscores
        $header = strtolower(trim($content));
        $header = preg_replace('/[^a-z0-9_ ]/', '', $header);
        $header = str_replace(' ', '_', $header);
        return $header ?: 'unnamed_column';
    }
}
