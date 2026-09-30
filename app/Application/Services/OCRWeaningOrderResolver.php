<?php

declare(strict_types=1);

namespace App\Application\Services;

/**
 * Cleans the weaning order code as read off a scanned DEST-01 sheet. The mirror of
 * OCRTransferOrderResolver: only digits after the prefix, so O is 0 and I or L are 1.
 */
final class OCRWeaningOrderResolver
{
    /**
     * Normalises a code as read off paper, or null when there is no code in it.
     */
    public function cleanCode(string $raw): ?string
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $raw));

        // The sequence is printed with four digits, but a reading may lose leading zeros
        // ("DS-20260929-001"): it is padded back, the way the order codes are numbered.
        if (!preg_match('/DS[-_.]?([0-9OIL]{8})[-_.]?([0-9OIL]{1,4})(?![0-9OIL])/', $compact, $matches)) {
            return null;
        }

        $digits = fn (string $value): string => strtr($value, ['O' => '0', 'I' => '1', 'L' => '1']);

        return 'DS-' . $digits($matches[1]) . '-' . str_pad($digits($matches[2]), 4, '0', STR_PAD_LEFT);
    }
}
