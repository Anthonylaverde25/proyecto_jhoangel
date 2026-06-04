<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

use App\Core\Interfaces\IWorkTemplateRepository;
use App\Core\Services\WorkdayCodeGenerator;
use App\Infrastructure\OCR\OCRProviderFactory;
use App\Models\Provider;
use App\Models\Farm;
use App\Models\Batch;
use Illuminate\Http\UploadedFile;

final class IdentifyWorkTemplateUseCase
{
    public function __construct(
        private readonly IWorkTemplateRepository $repository,
        private readonly WorkdayCodeGenerator $workdayCodeGenerator
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
        $ocrProvider = OCRProviderFactory::make($providerName);
        $analysisResult = $ocrProvider->analyze($file);

        $tables = $analysisResult['tables'] ?? $analysisResult;
        $metadata = $analysisResult['metadata'] ?? [];

        // 2. Extract Template Code
        $templateCode = null;

        // Try extracting from KVPs first
        foreach ($metadata as $key => $val) {
            $cleanKey = str_replace('_', '', strtolower($key));
            if ($cleanKey === 'templatecode') {
                $templateCode = trim($val);
                break;
            }
        }

        // Try extracting from the first table
        if (!$templateCode && !empty($tables)) {
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
                $templateCode = trim($firstRow[$templateCodeColumn]['value'] ?? '');
            }
        }

        // 3. Retrieve identified template
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

        // 4. Resolve Context Metadata (cuit, renspa, lote)
        // Check first table for header metadata in case KVP is empty
        if (empty(array_filter($metadata)) && isset($tables[0])) {
            $firstTable = $tables[0];
            $hasHeaderKeywords = false;
            foreach ($firstTable['headers'] as $h) {
                if (in_array($h, ['cuit', 'renspa', 'lote', 'alias', 'establecimiento'])) {
                    $hasHeaderKeywords = true;
                    break;
                }
            }

            if ($hasHeaderKeywords && !empty($firstTable['rows'])) {
                $row = $firstTable['rows'][0];
                foreach ($firstTable['headers'] as $h) {
                    if (isset($row[$h]['value'])) {
                        $metadata[$h] = $row[$h]['value'];
                    }
                }
            }
        }

        $cuit = $metadata['cuit'] ?? null;
        $renspa = $metadata['renspa'] ?? null;
        $lote = $metadata['lote'] ?? $metadata['alias'] ?? null;

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

        $contextDto = [
            'cuit' => $cuit,
            'renspa' => $renspa,
            'lote' => $lote,
            'provider_id' => $provider?->id,
            'farm_id' => $farm?->id,
            'batch_id' => $batch?->id,
        ];

        return [
            'identified_template' => $identifiedTemplate,
            'context'             => $contextDto,
            'suggested_workday_code' => $this->workdayCodeGenerator->generateForDate(new \DateTime()),
        ];
    }
}
