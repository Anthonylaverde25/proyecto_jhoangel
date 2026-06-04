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
        private readonly IWorkTemplateRepository $repository
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

        Log::info('Azure OCR Response Model', ['templateCode' => $templateCode]);
        Log::info('identifiedTemplate', ['identifiedTemplate' => $identifiedTemplate]);

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
        $serviceOrder = $this->resolveServiceOrder($metadata, $tables, $file, $companyId);

        $contextDto = [
            'cuit'                => $cuit,
            'renspa'              => $renspa,
            'lote'                => $lote,
            'establecimiento'     => $establecimiento ?? $farm?->name ?? null,
            'fecha'               => $fecha,
            'provider_id'         => $provider?->id,
            'farm_id'             => $farm?->id,
            'batch_id'            => $batch?->id,
            'service_order_code'  => $serviceOrder?->code,
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

    /**
     * Resolve the Service Order from OCR metadata, tables, or filename fallback.
     *
     * @param array $metadata
     * @param array $tables
     * @param UploadedFile|null $file
     * @param int $companyId
     * @return ServiceOrder|null
     */
    private function resolveServiceOrder(array $metadata, array $tables, ?UploadedFile $file, int $companyId): ?ServiceOrder
    {
        $serviceOrderCode = null;

        // 1. Search in resolved metadata KVPs
        foreach ($metadata as $key => $value) {
            $cleanKey = str_replace(['_', ' '], '', strtolower($key));
            if (in_array($cleanKey, ['serviceorder', 'serviceordercode', 'ordendeservicio'])) {
                $serviceOrderCode = trim((string)$value);
                break;
            }
        }

        // 2. Search in table column headers (first and second tables)
        if (!$serviceOrderCode) {
            foreach ($tables as $table) {
                $soColumn = null;
                foreach ($table['headers'] ?? [] as $h) {
                    $cleanH = str_replace(['_', ' '], '', strtolower($h));
                    if (in_array($cleanH, ['serviceorder', 'serviceordercode', 'ordendeservicio'])) {
                        $soColumn = $h;
                        break;
                    }
                }

                if ($soColumn && !empty($table['rows'])) {
                    $candidate = trim((string)($table['rows'][0][$soColumn]['value'] ?? ''));
                    if ($candidate !== '') {
                        $serviceOrderCode = $candidate;
                        break;
                    }
                }
            }
        }

        // 3. Fallback: Search in the filename (pattern SO-XXXXX)
        if (!$serviceOrderCode && $file) {
            $filename = $file->getClientOriginalName();
            if (preg_match('/(SO-[A-Za-z0-9-]+)/', $filename, $matches)) {
                $serviceOrderCode = $matches[1];
            }
        }

        if ($serviceOrderCode) {
            Log::info("WorkTemplateIdentificationService: Resolved Service Order Code from OCR: {$serviceOrderCode}");
            return ServiceOrder::where('code', $serviceOrderCode)
                ->where('company_id', $companyId)
                ->first();
        }

        return null;
    }
}

