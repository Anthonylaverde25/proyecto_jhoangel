<?php

declare(strict_types=1);

namespace App\Infrastructure\OCR;

use App\Core\Interfaces\IOCRProvider;
use Google\Cloud\DocumentAI\V1\Client\DocumentProcessorServiceClient;
use Google\Cloud\DocumentAI\V1\ProcessRequest;
use Google\Cloud\DocumentAI\V1\RawDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class GoogleOCRProvider implements IOCRProvider
{
    private string $projectId;
    private string $location;
    private string $processorId;
    private string $credentialsPath;

    public function __construct()
    {
        $this->projectId = (string) config('services.google.project_id');
        $this->location = (string) config('services.google.location', 'us');
        $this->processorId = (string) config('services.google.processor_id');
        $this->credentialsPath = base_path((string) config('services.google.credentials'));
    }

    /**
     * {@inheritdoc}
     */
    public function analyze(UploadedFile $file): array
    {
        if (!$this->projectId || !$this->processorId) {
            throw new \RuntimeException('Google Document AI credentials not configured.');
        }

        try {
            $client = new DocumentProcessorServiceClient([
                'apiEndpoint' => "{$this->location}-documentai.googleapis.com",
                'credentials' => $this->credentialsPath
            ]);

            $name = $client->processorName($this->projectId, $this->location, $this->processorId);

            $rawDocument = new RawDocument();
            $rawDocument->setContent(file_get_contents($file->getRealPath()));
            $rawDocument->setMimeType($file->getMimeType());

            $request = new ProcessRequest();
            $request->setName($name);
            $request->setRawDocument($rawDocument);

            $response = $client->processDocument($request);
            $document = $response->getDocument();

            return $this->parseTableResults($document);

        } catch (\Exception $e) {
            Log::error('Google Document AI Exception', ['message' => $e->getMessage()]);
            throw new \RuntimeException('Failed to process document with Google Document AI: ' . $e->getMessage());
        }
    }

    /**
     * Map Google Document AI response to unified internal format.
     */
    private function parseTableResults($document): array
    {
        $parsedTables = [];
        $fullText = $document->getText();

        foreach ($document->getPages() as $pageIdx => $page) {
            foreach ($page->getTables() as $tableIdx => $table) {
                $parsedTable = [
                    'table_id' => "p{$pageIdx}_t{$tableIdx}",
                    'row_count' => count($table->getHeaderRows()) + count($table->getBodyRows()),
                    'column_count' => 0,
                    'rows' => [],
                    'headers' => []
                ];

                // 1. Extract Headers
                foreach ($table->getHeaderRows() as $row) {
                    foreach ($row->getCells() as $cellIdx => $cell) {
                        $content = $this->getTextFromLayout($cell->getLayout(), $fullText);
                        $parsedTable['headers'][] = $this->sanitizeHeader($content);
                    }
                }
                $parsedTable['column_count'] = count($parsedTable['headers']);

                // 2. Extract Body Rows
                foreach ($table->getBodyRows() as $rowIdx => $row) {
                    $rowData = [];
                    foreach ($row->getCells() as $cellIdx => $cell) {
                        $headerName = $parsedTable['headers'][$cellIdx] ?? "column_{$cellIdx}";
                        $content = $this->getTextFromLayout($cell->getLayout(), $fullText);
                        
                        $rowData[$headerName] = [
                            'value' => trim($content),
                            'confidence' => $cell->getLayout()->getConfidence(),
                        ];
                    }
                    $parsedTable['rows'][] = $rowData;
                }

                $parsedTables[] = $parsedTable;
            }
        }

        return $parsedTables;
    }

    private function getTextFromLayout($layout, string $fullText): string
    {
        $text = '';
        foreach ($layout->getTextAnchor()->getTextSegments() as $segment) {
            $start = $segment->getStartIndex();
            $end = $segment->getEndIndex();
            $text .= substr($fullText, (int)$start, (int)($end - $start));
        }
        return $text;
    }

    private function sanitizeHeader(string $content): string
    {
        $header = strtolower(trim($content));
        $header = preg_replace('/[^a-z0-9_ ]/', '', $header);
        $header = str_replace(' ', '_', $header);
        return $header ?: 'unnamed_column';
    }
}
