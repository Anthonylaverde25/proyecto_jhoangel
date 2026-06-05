<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Interfaces\IWorkTemplateRepository;
use App\Models\Provider;
use App\Models\Farm;
use App\Models\Batch;
use App\Models\ServiceOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

final class WorkTemplateIdentificationService
{
    public function __construct(
        private readonly IWorkTemplateRepository $repository,
        private readonly OCRServiceOrderResolver $serviceOrderResolver
    ) {
    }

    /**
     * Identify work template and resolve context metadata from OCR analysis results.
     *
     * @param array $analysisResult
     * @param int $companyId
     * @param UploadedFile|null $file
     * @return array
     */
    public function identify(array $analysisResult, int $companyId, ?UploadedFile $file = null): array
    {
        $tables = $analysisResult['tables'] ?? [];
        $metadata = $analysisResult['metadata'] ?? [];

        // 1. Extract Template Code
        $templateCode = $this->extractTemplateCode($metadata, $tables);

        // 2. Retrieve identified template
        $identifiedTemplate = null;
        if ($templateCode) {
            $templates = $this->repository->findBy([
                'company_id' => $companyId,
                'code'       => $templateCode,
            ]);

            if (!empty($templates)) {
                $identifiedTemplate = $templates[0];
            }
        }

        // Log::info('Azure OCR Response Model', ['templateCode' => $templateCode]);
        // Log::info('identifiedTemplate', ['identifiedTemplate' => $identifiedTemplate]);

        // 3. Resolve Context Metadata (cuit, renspa, lote, establecimiento, fecha, service_order)
        $metadata = $this->enrichMetadataFromTables($metadata, $tables);

        $cuit = $metadata['cuit'] ?? null;
        $renspa = $metadata['renspa'] ?? null;
        $lote = $metadata['lote'] ?? $metadata['alias'] ?? null;
        $establecimiento = $metadata['establecimiento'] ?? null;
        $fecha = $metadata['fecha'] ?? null;

        $provider = null;
        $farm = null;
        $batch = null;

        if ($cuit) {
            $cleanCuit = preg_replace('/[^0-9]/', '', $cuit);
            $provider = Provider::whereRaw("REPLACE(cuit, '-', '') = ?", [$cleanCuit])->first();
        }

        if ($provider && $renspa) {
            $cleanRenspa = preg_replace('/[^a-zA-Z0-9]/', '', $renspa);
            $farm = Farm::where('provider_id', $provider->id)
                ->whereRaw("REPLACE(REPLACE(renspa, '.', ''), '/', '') = ?", [$cleanRenspa])
                ->first();
        }

        if ($farm && $lote) {
            $batch = Batch::where('farm_id', $farm->id)
                ->where('name', $lote)
                ->first();
        }

        // 4. Resolve Service Order from OCR metadata, tables, or filename fallback
        $serviceOrder = $this->serviceOrderResolver->resolve([
            'metadata' => $metadata,
            'tables' => $tables,
        ], $file, $companyId);

        $serviceOrderCode = $serviceOrder?->code;
        if (!$serviceOrderCode) {
            $serviceOrderCode = $this->serviceOrderResolver->resolveCandidateCode([
                'metadata' => $metadata,
                'tables' => $tables,
            ], $file);
        }

        $contextDto = [
            'cuit'                => $cuit,
            'renspa'              => $renspa,
            'lote'                => $lote,
            'establecimiento'     => $establecimiento ?? $farm?->name ?? null,
            'fecha'               => $fecha,
            'provider_id'         => $provider?->id,
            'farm_id'             => $farm?->id,
            'batch_id'            => $batch?->id,
            'service_order_code'  => $serviceOrderCode,
            'service_order_id'    => $serviceOrder?->id,
        ];

        return [
            'identified_template' => $identifiedTemplate,
            'context'             => $contextDto,
        ];
    }

    /**
     * Extract the template code from metadata or tables.
     *
     * @param array $metadata
     * @param array $tables
     * @return string|null
     */
    private function extractTemplateCode(array $metadata, array $tables): ?string
    {
        // Try extracting from KVPs first
        foreach ($metadata as $key => $val) {
            $cleanKey = str_replace('_', '', strtolower($key));
            if ($cleanKey === 'templatecode') {
                return trim($val);
            }
        }

        // Try extracting from the first table
        if (!empty($tables)) {
            $firstTable = $tables[0];
            $templateCodeColumn = null;
            foreach ($firstTable['headers'] as $header) {
                $cleanHeader = str_replace('_', '', strtolower($header));
                if ($cleanHeader === 'templatecode') {
                    $templateCodeColumn = $header;
                    break;
                }
            }

            if ($templateCodeColumn && !empty($firstTable['rows'])) {
                $firstRow = $firstTable['rows'][0];
                return trim($firstRow[$templateCodeColumn]['value'] ?? '');
            }
        }

        return null;
    }

    /**
     * Enrich metadata using table headers if metadata KVPs are empty.
     *
     * @param array $metadata
     * @param array $tables
     * @return array
     */
    private function enrichMetadataFromTables(array $metadata, array $tables): array
    {
        // Known header field keywords for extraction from table rows
        $knownFields = [
            'cuit', 'renspa', 'lote', 'alias', 'establecimiento', 'fecha',
            'service_order', 'serviceorder', 'service order',
        ];

        if (empty(array_filter($metadata)) && isset($tables[0])) {
            $firstTable = $tables[0];
            $hasHeaderKeywords = false;
            foreach ($firstTable['headers'] as $h) {
                $cleanHeader = str_replace(['_', ' '], '', strtolower($h));
                foreach ($knownFields as $known) {
                    if ($cleanHeader === str_replace(['_', ' '], '', $known)) {
                        $hasHeaderKeywords = true;
                        break 2;
                    }
                }
            }

            if ($hasHeaderKeywords && !empty($firstTable['rows'])) {
                $row = $firstTable['rows'][0];
                foreach ($firstTable['headers'] as $h) {
                    if (isset($row[$h]['value'])) {
                        $normalizedKey = str_replace([' '], '_', strtolower($h));
                        $metadata[$normalizedKey] = $row[$h]['value'];
                    }
                }
            }
        }

        // Also scan secondary tables (table index 1) for header metadata
        if (isset($tables[1])) {
            $secondTable = $tables[1];
            foreach ($secondTable['headers'] as $h) {
                $cleanHeader = str_replace(['_', ' '], '', strtolower($h));
                foreach ($knownFields as $known) {
                    $cleanKnown = str_replace(['_', ' '], '', $known);
                    if ($cleanHeader === $cleanKnown && !isset($metadata[$known])) {
                        if (!empty($secondTable['rows'][0][$h]['value'])) {
                            $normalizedKey = str_replace([' '], '_', strtolower($h));
                            $metadata[$normalizedKey] = $secondTable['rows'][0][$h]['value'];
                        }
                    }
                }
            }
        }

        return $metadata;
    }


}

