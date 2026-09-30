<?php

declare(strict_types=1);

namespace App\Application\Services;

/**
 * Cleans the birth order code as read off a scanned PAR-01 sheet. The mirror of
 * OCRWeaningOrderResolver: only digits after the prefix, so O is 0 and I or L are 1.
 */
final class OCRBirthOrderResolver
{
    /**
     * Normalises a code as read off paper, or null when there is no code in it.
     */
    public function cleanCode(string $raw): ?string
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $raw));

        // "PAR-01" is the template code printed on the same sheet: never an order code.
        $compact = (string) preg_replace('/PAR[-_.]?0?1(?![0-9OIL])/', '', $compact);

        // The sequence is printed with four digits, but a reading may lose leading zeros
        // ("PA-20260929-001"): it is padded back, the way the order codes are numbered.
        if (!preg_match('/PA[-_.]?([0-9OIL]{8})[-_.]?([0-9OIL]{1,4})(?![0-9OIL])/', $compact, $matches)) {
            return null;
        }

        $digits = fn (string $value): string => strtr($value, ['O' => '0', 'I' => '1', 'L' => '1']);

        return 'PA-' . $digits($matches[1]) . '-' . str_pad($digits($matches[2]), 4, '0', STR_PAD_LEFT);
    }
}
