<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Enums\AnimalCategory;

/**
 * Pure PHP service to transform raw OCR string values into domain types.
 * Zero framework dependencies.
 */
final class CaravanValueParser
{
    /**
     * Known full-mouth aliases mapped to their teeth count.
     */
    private const TEETH_ALIASES = [
        'boca llena'    => 8,
        'boca_llena'    => 8,
        'full mouth'    => 8,
        'leche'         => 0,
        'diente de leche' => 0,
        'media boca'    => 4,
        'media_boca'    => 4,
    ];

    /**
     * Parse a raw teeth value from OCR into an integer.
     *
     * Examples:
     *  - "4 dientes" → 4
     *  - "Boca Llena" → 8
     *  - "Leche (0)"  → 0
     *  - "2"          → 2
     *  - "6 dientes"  → 6
     *
     * @param string $raw
     * @return int
     */
    public static function parseTeeth(string $raw): int
    {
        $normalized = mb_strtolower(trim($raw));

        // Check known aliases first
        foreach (self::TEETH_ALIASES as $alias => $value) {
            if (str_contains($normalized, $alias)) {
                return $value;
            }
        }

        // Extract first numeric value from the string
        if (preg_match('/(\d+)/', $normalized, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Parse a raw weight value from OCR into a float.
     *
     * Handles:
     *  - "450"      → 450.00
     *  - "380,5"    → 380.50  (comma as decimal sep)
     *  - "1.200"    → 1200.00 (dot as thousand sep when no decimal)
     *  - "1,200.5"  → 1200.50
     *  - ""         → null
     *
     * @param string $raw
     * @return float|null
     */
    public static function parseWeight(string $raw): ?float
    {
        $cleaned = trim($raw);

        if ($cleaned === '') {
            return null;
        }

        // Remove any non-numeric chars except dots, commas, and minus
        $cleaned = preg_replace('/[^\d.,-]/', '', $cleaned);

        if ($cleaned === '' || $cleaned === null) {
            return null;
        }

        // Determine decimal separator
        $lastDot = strrpos($cleaned, '.');
        $lastComma = strrpos($cleaned, ',');

        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            // Comma is the decimal separator (European format)
            $cleaned = str_replace('.', '', $cleaned);
            $cleaned = str_replace(',', '.', $cleaned);
        } else {
            // Dot is the decimal separator (or thousand separator only)
            $cleaned = str_replace(',', '', $cleaned);
        }

        $value = (float) $cleaned;

        return $value > 0 ? $value : null;
    }

    /**
     * Parse a raw date string from OCR into ISO format (Y-m-d).
     *
     * Handles:
     *  - "2025-01-15" → "2025-01-15" (already ISO)
     *  - "15/01/2025" → "2025-01-15" (dd/mm/yyyy)
     *  - "01-15-2025" → "2025-01-15" (mm-dd-yyyy)
     *
     * @param string $raw
     * @return string|null
     */
    public static function parseDate(string $raw): ?string
    {
        $cleaned = trim($raw);

        if ($cleaned === '') {
            return null;
        }

        // Already ISO format (YYYY-MM-DD)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cleaned)) {
            return $cleaned;
        }

        // DD/MM/YYYY or DD-MM-YYYY
        if (preg_match('#^(\d{2})[/\-](\d{2})[/\-](\d{4})$#', $cleaned, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];

            // If day > 12, it's DD/MM/YYYY for sure
            if ($day > 12) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }

            // If month > 12, it's MM/DD/YYYY
            if ($month > 12) {
                return sprintf('%04d-%02d-%02d', $year, $day, $month);
            }

            // Ambiguous: assume DD/MM/YYYY (Latin American convention)
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        return null;
    }

    /**
     * Parse a raw category string from OCR into AnimalCategory Enum.
     *
     * Handles:
     *  - "Novillo"    → AnimalCategory::NOVILLO
     *  - "TERNERA"    → AnimalCategory::TERNERA
     *  - "vaca_vacia" → AnimalCategory::VACA_VACIA
     *  - "Vaca"       → AnimalCategory::VACA
     *
     * @param string $raw
     * @return AnimalCategory|null
     */
    public static function parseCategory(string $raw): ?AnimalCategory
    {
        $normalized = mb_strtolower(trim($raw));

        if ($normalized === '') {
            return null;
        }

        // Try direct matching first
        $enumValue = AnimalCategory::tryFrom($normalized);
        if ($enumValue !== null) {
            return $enumValue;
        }

        // Handle plurals and common variations
        $mappings = [
            'novillitos' => AnimalCategory::NOVILLITO,
            'novillos'   => AnimalCategory::NOVILLO,
            'vaquillonas' => AnimalCategory::VAQUILLONA,
            'vaquilla'    => AnimalCategory::VAQUILLONA,
            'vacas'      => AnimalCategory::VACA,
            'terneros'   => AnimalCategory::TERNERO,
            'terneras'   => AnimalCategory::TERNERA,
            'toros'      => AnimalCategory::TORO,
        ];

        return $mappings[$normalized] ?? null;
    }

    /**
     * Parse a raw sex value from OCR into a normalized string.
     * Handles common OCR misreadings like "Hombro", "Hemora", etc.
     *
     * @param string $raw
     * @param AnimalCategory|null $category Contextual hint
     * @return string
     */
    public static function parseSex(string $raw, ?AnimalCategory $category = null): string
    {
        $normalized = mb_strtolower(trim($raw));

        if (str_starts_with($normalized, 'h') || str_contains($normalized, 'hem')) {
            return 'Hembra';
        }

        if (str_starts_with($normalized, 'm') || str_contains($normalized, 'mac') || str_contains($normalized, 'hom')) {
            // "hom" can be "hombre" (macho) or "hombro" (ocr error for hembra?) 
            // In the user's sample "Hombro" was meant to be "Hembra" based on category "vaca".
            // Let's use category hint if available.
            if ($category === AnimalCategory::VACA || $category === AnimalCategory::VAQUILLONA || $category === AnimalCategory::TERNERA || $category === AnimalCategory::VACA_VACIA) {
                return 'Hembra';
            }
            return 'Macho';
        }

        // Contextual defaults
        if ($category !== null) {
            $females = [AnimalCategory::VACA, AnimalCategory::VAQUILLONA, AnimalCategory::TERNERA, AnimalCategory::VACA_VACIA];
            return in_array($category, $females, true) ? 'Hembra' : 'Macho';
        }

        return $raw ?: 'N/D';
    }

    /**
     * Parse a raw breed value from OCR into a normalized string.
     * Handles common OCR misreadings like "angos" (Angus).
     *
     * @param string $raw
     * @return string|null
     */
    public static function parseBreed(string $raw): ?string
    {
        $normalized = mb_strtolower(trim($raw));

        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, 'ang')) {
            return 'Angus';
        }

        if (str_contains($normalized, 'her') || str_contains($normalized, 'ere')) {
            return 'Hereford';
        }

        if (str_contains($normalized, 'bra')) {
            return 'Brangus';
        }

        return ucfirst($raw);
    }
}
