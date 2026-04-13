<?php

declare(strict_types=1);

namespace App\Infrastructure\OCR;

use App\Core\Interfaces\IOCRProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AzureOCRProvider implements IOCRProvider
{
    private string $apiKey;
    private string $endpoint;

    public function __construct()
    {
        $this->apiKey = (string) config('services.azure.key');
        $this->endpoint = rtrim((string) config('services.azure.endpoint'), '/');
    }

    /**
     * {@inheritdoc}
     */
    public function analyze(UploadedFile $file): array
    {
        if (!$this->apiKey || !$this->endpoint) {
            throw new \RuntimeException('Azure Document Intelligence credentials not configured.');
        }

        $fileContent = file_get_contents($file->getRealPath());

        // 1. Submit the document to Azure (Layout Model)
        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $this->apiKey,
            'Content-Type' => $file->getMimeType(),
        ])->withBody($fileContent, $file->getMimeType())
            ->post("{$this->endpoint}/documentintelligence/documentModels/prebuilt-layout:analyze?api-version=2024-11-30");

        if ($response->failed()) {
            Log::error('Azure API Submission Failed', ['details' => $response->json()]);
            throw new \RuntimeException('Failed to submit document to Azure.');
        }

        $operationUrl = $response->header('Operation-Location');
        if (!$operationUrl) {
            throw new \RuntimeException('Operation-Location header not found.');
        }

        // 2. Poll for the result
        $status = 'running';
        $maxAttempts = 15;
        $attempts = 0;
        $resultResponse = null;

        while (in_array($status, ['running', 'notStarted']) && $attempts < $maxAttempts) {
            sleep(2);
            $resultResponse = Http::withHeaders([
                'Ocp-Apim-Subscription-Key' => $this->apiKey
            ])->get($operationUrl);

            $status = $resultResponse->json('status');
            $attempts++;
        }

        if ($status !== 'succeeded') {
            throw new \RuntimeException("Analysis did not complete in time or failed. Status: {$status}");
        }

        $analyzeResult = $resultResponse->json('analyzeResult');
        return $this->parseTables($analyzeResult['tables'] ?? []);
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

            // 1. Identify headers
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
                if (($cell['kind'] ?? '') === 'columnHeader' || ($cell['rowIndex'] === 0)) {
                    continue;
                }

                $rowIndex = $cell['rowIndex'];
                $columnIndex = $cell['columnIndex'];
                $headerName = $headers[$columnIndex] ?? "column_{$columnIndex}";

                $rows[$rowIndex][$headerName] = [
                    'value' => $cell['content'] ?? '',
                    'confidence' => $cell['confidence'] ?? 1.0,
                ];
            }

            $parsedTable['rows'] = array_values($rows);
            $parsedTables[] = $parsedTable;
        }

        return $parsedTables;
    }

    /**
     * Sanitize header names.
     *
     * @param string $content
     * @return string
     */
    private function sanitizeHeader(string $content): string
    {
        $header = strtolower(trim($content));
        $header = preg_replace('/[^a-z0-9_ ]/', '', $header);
        $header = str_replace(' ', '_', $header);
        return $header ?: 'unnamed_column';
    }
}
