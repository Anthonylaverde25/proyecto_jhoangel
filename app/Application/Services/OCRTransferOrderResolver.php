<?php

declare(strict_types=1);

namespace App\Application\Services;

use Illuminate\Http\UploadedFile;

/**
 * Finds the transfer order code on a scanned CACT-01 sheet: in the header metadata, in the
 * rows, or in the file name, in that order. The mirror of OCRServiceOrderResolver.
 *
 * The code was designed with only digits after the prefix, so a letter read where a digit must
 * be is a scanning error and can be corrected without guessing: O is 0, I and L are 1.
 */
final class OCRTransferOrderResolver
{
    /**
     * @param array<string, mixed> $analysis
     */
    public function resolveCandidateCode(array $analysis, ?UploadedFile $file = null): ?string
    {
        foreach (['orden_transferencia', 'transfer_order'] as $field) {
            $value = $analysis['metadata'][$field] ?? $analysis['context'][$field] ?? null;

            if (is_string($value) && ($code = $this->cleanCode($value)) !== null) {
                return $code;
            }
        }

        foreach ((array) ($analysis['tables'] ?? []) as $table) {
            foreach ((array) ($table['rows'] ?? []) as $row) {
                $value = $row['orden_transferencia'] ?? null;
                $value = is_array($value) ? ($value['value'] ?? null) : $value;

                if (is_string($value) && ($code = $this->cleanCode($value)) !== null) {
                    return $code;
                }
            }
        }

        if ($file instanceof UploadedFile) {
            return $this->cleanCode($file->getClientOriginalName());
        }

        return null;
    }

    /**
     * Normalises a code as read off paper, or null when there is no code in it.
     */
    public function cleanCode(string $raw): ?string
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $raw));

        if (!preg_match('/TR[-_.]?([0-9OIL]{8})[-_.]?([0-9OIL]{4})/', $compact, $matches)) {
            return null;
        }

        $digits = fn (string $value): string => strtr($value, ['O' => '0', 'I' => '1', 'L' => '1']);

        return 'TR-' . $digits($matches[1]) . '-' . $digits($matches[2]);
    }
}
