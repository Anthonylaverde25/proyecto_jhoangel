<?php

declare(strict_types=1);

namespace App\Application\Services;

use Illuminate\Support\Facades\Log;

class OCRNormalizationService
{
    /**
     * Common handwritten ambiguities mapping per field type.
     */
    private const CORRECTION_MATRIX = [
        'identification' => [
            '/' => '1',
            '|' => '1',
            'l' => '1',
            'S' => '5',
            's' => '5',
            'O' => '0',
            'o' => '0',
            'G' => '6',
        ],
        'teeth' => [
            'I' => '1',
            'l' => '1',
            'o' => '0',
            'O' => '0',
            'Z' => '2',
            'z' => '2',
            'S' => '5',
            's' => '5',
        ],
    ];

    /**
     * Normalize an OCR extracted value based on its target field.
     *
     * @param string $value
     * @param string|null $targetField
     * @return string
     */
    public function normalize(string $value, ?string $targetField = null): string
    {
        // 1. Core Cleanup (Remove OCR Noise)
        $cleanValue = $this->basicCleanup($value);

        if (empty($cleanValue)) {
            return '';
        }

        // 2. Apply Correction Matrix if field is known
        if ($targetField && isset(self::CORRECTION_MATRIX[$targetField])) {
            $cleanValue = $this->applyMatrix($cleanValue, self::CORRECTION_MATRIX[$targetField]);
        }

        // 3. Field Specific Final Polish
        return match ($targetField) {
            'identification'                   => $this->normalizeIdentification($cleanValue),
            'teeth'                            => $this->normalizeTeeth($cleanValue),
            'entry_weight', 'exit_weight'     => $this->normalizeWeight($cleanValue),
            'category'                         => mb_strtolower($cleanValue),
            'breed'                            => mb_strtolower($cleanValue),
            default                            => $cleanValue
        };
    }

    /**
     * Removes newlines and Azure specific selection marks.
     */
    private function basicCleanup(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        $value = str_replace([':selected:', ':unselected:'], '', $value);
        return trim($value);
    }

    /**
     * Replaces characters based on a predefined confusion/correction map.
     */
    private function applyMatrix(string $value, array $matrix): string
    {
        // For identifications, prioritize converting / to 1 even with surrounding spaces
        $value = strtr($value, $matrix);

        return $value;
    }

    /**
     * Ensures identification is alphanumeric and cleans leading/trailing symbols.
     */
    private function normalizeIdentification(string $value): string
    {
        // 1. Remove all spaces first
        $value = str_replace(' ', '', $value);

        // 2. Remove noise only at the VERY start or VERY end, 
        // but NEVER if it looks like a valid part of the number
        $value = trim($value, '. ');
        
        return $value;
    }

    /**
     * Extracts ONLY the first numeric sequence for teeth.
     */
    private function normalizeTeeth(string $value): string
    {
        if (preg_match('/\d+/', $value, $matches)) {
            return $matches[0];
        }
        return $value;
    }

    /**
     * Normalizes weight values by keeping decimals and digits.
     */
    private function normalizeWeight(string $value): string
    {
        // Replace comma with dot
        $value = str_replace(',', '.', $value);
        
        // Remove everything except numbers and dots
        $value = preg_replace('/[^0-9.]/', '', $value);
        
        return $value;
    }
}
