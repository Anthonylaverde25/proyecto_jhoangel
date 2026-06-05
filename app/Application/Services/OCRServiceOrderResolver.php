<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\ServiceOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

final class OCRServiceOrderResolver
{
    /**
     * Resolve the Service Order associated with this file / analysis result.
     *
     * @param array $analysisResult
     * @param UploadedFile|null $file
     * @param int $companyId
     * @return ServiceOrder|null
     */
    public function resolve(array $analysisResult, ?UploadedFile $file, int $companyId): ?ServiceOrder
    {
        // 1. Search in KVPs metadata
        $metadata = $analysisResult['metadata'] ?? [];
        foreach ($metadata as $key => $value) {
            $cleanKey = str_replace(['_', ' '], '', strtolower($key));
            if (in_array($cleanKey, ['serviceorder', 'serviceordercode', 'ordendeservicio'])) {
                $candidate = str_replace(' ', '', trim((string)$value));
                if ($candidate !== '') {
                    $so = ServiceOrder::where('code', $candidate)
                        ->where('company_id', $companyId)
                        ->first();
                    if ($so) {
                        return $so;
                    }
                }
            }
        }

        // 2. Search in table column headers (all tables)
        if (!empty($analysisResult['tables'])) {
            foreach ($analysisResult['tables'] as $table) {
                $soColumn = null;
                foreach ($table['headers'] ?? [] as $h) {
                    $cleanH = str_replace(['_', ' '], '', strtolower($h));
                    if (in_array($cleanH, ['serviceorder', 'serviceordercode', 'ordendeservicio'])) {
                        $soColumn = $h;
                        break;
                    }
                }

                if ($soColumn && !empty($table['rows'])) {
                    $candidate = str_replace(' ', '', trim((string)($table['rows'][0][$soColumn]['value'] ?? '')));
                    if ($candidate !== '') {
                        // Query database with first row candidate immediately
                        $so = ServiceOrder::where('code', $candidate)
                            ->where('company_id', $companyId)
                            ->first();
                        if ($so) {
                            return $so;
                        }

                        // Try concatenating all rows under this column to handle split values
                        $concatenated = '';
                        foreach ($table['rows'] as $row) {
                            $concatenated .= trim((string)($row[$soColumn]['value'] ?? ''));
                        }
                        $concatenated = str_replace(' ', '', $concatenated);
                        
                        if ($concatenated !== '' && $concatenated !== $candidate) {
                            $so = ServiceOrder::where('code', $concatenated)
                                ->where('company_id', $companyId)
                                ->first();
                            if ($so) {
                                return $so;
                            }
                        }
                    }
                }
            }
        }

        // 3. Fallback: Search in the filename
        if ($file) {
            $filename = $file->getClientOriginalName();
            if (preg_match('/(SO-[A-Za-z0-9-]+)/', $filename, $matches)) {
                $candidate = str_replace(' ', '', $matches[1]);
                $so = ServiceOrder::where('code', $candidate)
                    ->where('company_id', $companyId)
                    ->first();
                if ($so) {
                    return $so;
                }
            }
        }

        return null;
    }

    /**
     * Resolve the candidate Service Order code from the analysis results or file.
     *
     * @param array $analysisResult
     * @param UploadedFile|null $file
     * @return string|null
     */
    public function resolveCandidateCode(array $analysisResult, ?UploadedFile $file): ?string
    {
        // 1. Search in KVPs metadata
        $metadata = $analysisResult['metadata'] ?? [];
        foreach ($metadata as $key => $value) {
            $cleanKey = str_replace(['_', ' '], '', strtolower($key));
            if (in_array($cleanKey, ['serviceorder', 'serviceordercode', 'ordendeservicio'])) {
                $candidate = str_replace(' ', '', trim((string)$value));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        // 2. Search in table column headers (all tables)
        if (!empty($analysisResult['tables'])) {
            foreach ($analysisResult['tables'] as $table) {
                $soColumn = null;
                foreach ($table['headers'] ?? [] as $h) {
                    $cleanH = str_replace(['_', ' '], '', strtolower($h));
                    if (in_array($cleanH, ['serviceorder', 'serviceordercode', 'ordendeservicio'])) {
                        $soColumn = $h;
                        break;
                    }
                }

                if ($soColumn && !empty($table['rows'])) {
                    // Try concatenating all rows under this column first to handle split values
                    $concatenated = '';
                    foreach ($table['rows'] as $row) {
                        $concatenated .= trim((string)($row[$soColumn]['value'] ?? ''));
                    }
                    $concatenated = str_replace(' ', '', $concatenated);
                    if ($concatenated !== '') {
                        return $concatenated;
                    }

                    $candidate = str_replace(' ', '', trim((string)($table['rows'][0][$soColumn]['value'] ?? '')));
                    if ($candidate !== '') {
                        return $candidate;
                    }
                }
            }
        }

        // 3. Fallback: Search in the filename
        if ($file) {
            $filename = $file->getClientOriginalName();
            if (preg_match('/(SO-[A-Za-z0-9-]+)/', $filename, $matches)) {
                return str_replace(' ', '', $matches[1]);
            }
        }

        return null;
    }
}

