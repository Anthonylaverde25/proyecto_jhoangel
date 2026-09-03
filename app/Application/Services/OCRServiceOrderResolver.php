<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\ServiceOrder;
use Illuminate\Http\UploadedFile;

class OCRServiceOrderResolver
{
    /**
     * Resolve ServiceOrder from OCR analysis payload or uploaded file fallback.
     *
     * @param array<string, mixed> $analysis
     * @param UploadedFile|null $file
     * @param int $companyId
     * @return ServiceOrder|null
     */
    public function resolve(array $analysis, ?UploadedFile $file, int $companyId): ?ServiceOrder
    {
        $code = $this->resolveCandidateCode($analysis, $file);

        if (!$code) {
            return null;
        }

        return ServiceOrder::where('company_id', $companyId)
            ->where('code', $code)
            ->first();
    }

    /**
     * Extract normalized candidate service order code from analysis or filename.
     *
     * @param array<string, mixed> $analysis
     * @param UploadedFile|null $file
     * @return string|null
     */
    public function resolveCandidateCode(array $analysis, ?UploadedFile $file): ?string
    {
        // 1. Check metadata
        if (isset($analysis['metadata']['service_order']) && is_string($analysis['metadata']['service_order'])) {
            $cleaned = $this->cleanCode($analysis['metadata']['service_order']);
            if ($cleaned !== null) {
                return $cleaned;
            }
        }

        // 2. Check tables
        if (isset($analysis['tables']) && is_array($analysis['tables'])) {
            $collectedParts = [];
            foreach ($analysis['tables'] as $table) {
                if (isset($table['rows']) && is_array($table['rows'])) {
                    foreach ($table['rows'] as $row) {
                        if (isset($row['service_order'])) {
                            $val = is_array($row['service_order']) ? ($row['service_order']['value'] ?? '') : (string)$row['service_order'];
                            if (trim($val) !== '') {
                                $collectedParts[] = trim($val);
                            }
                        }
                    }
                }
            }

            if (!empty($collectedParts)) {
                $concatenated = implode('', $collectedParts);
                $cleaned = $this->cleanCode($concatenated);
                if ($cleaned !== null) {
                    return $cleaned;
                }
            }
        }

        // 3. Fallback to filename
        if ($file instanceof UploadedFile) {
            $filename = $file->getClientOriginalName();
            if (preg_match('/(SO-[0-9A-Za-z\-_]+)/', $filename, $matches)) {
                $cleaned = $this->cleanCode($matches[1]);
                if ($cleaned !== null) {
                    return $cleaned;
                }
            }
        }

        return null;
    }

    /**
     * Clean and normalize a potential service order code string.
     */
    private function cleanCode(string $raw): ?string
    {
        // Remove whitespace within code
        $cleaned = trim(preg_replace('/\s+/', '', $raw) ?? '');

        // Extract SO- pattern if present
        if (preg_match('/(SO-[0-9A-Za-z\-]+)/i', $cleaned, $matches)) {
            return strtoupper($matches[1]);
        }

        if (str_starts_with(strtoupper($cleaned), 'SO-')) {
            return strtoupper($cleaned);
        }

        return $cleaned !== '' ? strtoupper($cleaned) : null;
    }
}
