<?php

declare(strict_types=1);

namespace App\Application\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

final class OCRTemplateDataProcessor
{
    public function __construct(
        private readonly Rep01TemplateProcessor $rep01Processor
    ) {
    }

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
                $this->rep01Processor->process($analysisResult, $companyId, $file);
                break;
            default:
                Log::warning("OCRTemplateDataProcessor: No processing logic registered for template: {$templateCode}");
                break;
        }
    }
}
