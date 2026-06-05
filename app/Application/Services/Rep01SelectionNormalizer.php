<?php

declare(strict_types=1);

namespace App\Application\Services;

/**
 * Normalizes REP-01 selection fields from raw OCR checkbox marks
 * into clean, semantic domain values.
 *
 * Azure OCR represents checkboxes as inline text with :selected: / :unselected: markers.
 * This normalizer maps those markers to typed domain values.
 */
final class Rep01SelectionNormalizer
{
    /**
     * Diagnosis option map: OCR label → semantic value.
     */
    private const DIAGNOSIS_MAP = [
        'preñada'  => 'PREGNANT',
        'prenada'  => 'PREGNANT',
        'preñ'     => 'PREGNANT',
        'pren'     => 'PREGNANT',
        'vacía'    => 'EMPTY',
        'vacia'    => 'EMPTY',
        'vac'      => 'EMPTY',
    ];

    /**
     * Gestation stage option map: OCR label → GestationStage enum value.
     */
    private const STAGE_MAP = [
        'cabeza'  => 'head',
        'cobeza'  => 'head',
        'cab'     => 'head',
        'cuerpo'  => 'body',
        'cue'     => 'body',
        'cola'    => 'tail',
        'col'     => 'tail',
    ];

    /**
     * Normalize a diagnosis field (checkbox: Preñada / Vacía).
     *
     * @param string $rawValue Raw OCR cell content with :selected:/:unselected: markers
     * @return array{value: string|null, label: string, confidence: float}
     */
    public function normalizeDiagnosis(string $rawValue): array
    {
        $selected = $this->extractSelectedOption($rawValue);

        if ($selected === null) {
            return ['value' => null, 'label' => '', 'confidence' => 0.5];
        }

        $normalized = $this->matchOption($selected, self::DIAGNOSIS_MAP);

        if ($normalized !== null) {
            return [
                'value'      => $normalized,
                'label'      => $normalized === 'PREGNANT' ? 'Preñada' : 'Vacía',
                'confidence' => 0.95,
            ];
        }

        // Could not map to a known value — return the raw text as-is
        return ['value' => $selected, 'label' => $selected, 'confidence' => 0.6];
    }

    /**
     * Normalize a gestation stage field (checkbox: Cabeza / Cuerpo / Cola).
     *
     * @param string $rawValue Raw OCR cell content with :selected:/:unselected: markers
     * @return array{value: string|null, label: string, confidence: float}
     */
    public function normalizeGestationStage(string $rawValue): array
    {
        $selected = $this->extractSelectedOption($rawValue);

        if ($selected === null) {
            return ['value' => null, 'label' => '', 'confidence' => 0.5];
        }

        $normalized = $this->matchOption($selected, self::STAGE_MAP);

        if ($normalized !== null) {
            $labelMap = ['head' => 'Cabeza', 'body' => 'Cuerpo', 'tail' => 'Cola'];
            return [
                'value'      => $normalized,
                'label'      => $labelMap[$normalized] ?? $selected,
                'confidence' => 0.95,
            ];
        }

        return ['value' => $selected, 'label' => $selected, 'confidence' => 0.6];
    }

    /**
     * Check if a raw value contains selection markers.
     */
    public function hasSelectionMarkers(string $value): bool
    {
        return str_contains($value, ':selected:') || str_contains($value, ':unselected:');
    }

    /**
     * Determine if a field key corresponds to a diagnosis column.
     */
    public function isDiagnosisField(string $fieldKey): bool
    {
        $clean = $this->cleanFieldKey($fieldKey);
        return in_array($clean, ['diagnostico', 'diagnstico', 'diagnosis'], true);
    }

    /**
     * Determine if a field key corresponds to a gestation stage column.
     */
    public function isGestationStageField(string $fieldKey): bool
    {
        $clean = $this->cleanFieldKey($fieldKey);
        return in_array($clean, [
            'estadioestimado', 'estadio', 'gestationalstage',
            'gestation_stage', 'gestational_stage', 'estadioestimadoccc',
        ], true);
    }

    /**
     * Determine if a field key corresponds to a category column.
     */
    public function isCategoryField(string $fieldKey): bool
    {
        $clean = $this->cleanFieldKey($fieldKey);
        return in_array($clean, ['category', 'categoria', 'categora'], true);
    }

    /**
     * Determine if a field key corresponds to an observations column.
     */
    public function isObservationsField(string $fieldKey): bool
    {
        $clean = $this->cleanFieldKey($fieldKey);
        return in_array($clean, ['observations', 'observaciones', 'observacion', 'observaci_ones'], true);
    }

    /**
     * Determine if a field key corresponds to a caravan/identification column.
     */
    public function isCaravanaField(string $fieldKey): bool
    {
        $clean = $this->cleanFieldKey($fieldKey);
        return in_array($clean, ['caravana', 'identification', 'caravanas', 'animal', 'caravana_id'], true);
    }

    /**
     * Extract the :selected: option text from a raw OCR string.
     *
     * Handles patterns like:
     *   "Preñada :selected: Vacía :unselected:"
     *   ":selected: Preñada :unselected: Vacía"
     *   ":unselected: Cabeza :unselected: Cuerpo :selected: Cola :unselected:"
     *
     * @return string|null The selected option text, or null if none found
     */
    private function extractSelectedOption(string $raw): ?string
    {
        if (!str_contains($raw, ':selected:')) {
            return null;
        }

        // Split by :selected: and :unselected: to isolate segments, preserving delimiters
        $parts = preg_split('/(:(?:un)?selected:)/ui', $raw, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!$parts) {
            return null;
        }

        // Clean parts to avoid trailing/leading spaces
        $parts = array_map('trim', $parts);

        // Determine if the first element is a marker
        $firstIsMarker = $this->isMarker($parts[0]);

        foreach ($parts as $index => $part) {
            if (strcasecmp($part, ':selected:') === 0) {
                // If the first element is a marker, the text for marker at $index is at $index + 1
                // Otherwise, the text is at $index - 1
                $targetIndex = $firstIsMarker ? $index + 1 : $index - 1;

                if (isset($parts[$targetIndex]) && !$this->isMarker($parts[$targetIndex])) {
                    $option = $parts[$targetIndex];
                    return $option !== '' ? $option : null;
                }
            }
        }

        return null;
    }

    /**
     * Helper to check if a token is a checkbox marker.
     */
    private function isMarker(string $part): bool
    {
        return strcasecmp($part, ':selected:') === 0 || strcasecmp($part, ':unselected:') === 0;
    }


    /**
     * Match extracted option text against a known option map using fuzzy matching.
     *
     * @param string $option The extracted option text
     * @param array<string, string> $map Label → semantic value map
     * @return string|null The matched semantic value, or null
     */
    private function matchOption(string $option, array $map): ?string
    {
        $normalized = mb_strtolower(trim($option));
        // Remove accents for resilient matching
        $normalized = $this->removeAccents($normalized);

        foreach ($map as $key => $value) {
            $cleanKey = $this->removeAccents($key);
            if ($normalized === $cleanKey || str_starts_with($normalized, $cleanKey)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Remove accents/diacritics from a string for comparison.
     */
    private function removeAccents(string $str): string
    {
        $normalized = \Normalizer::normalize($str, \Normalizer::FORM_D);
        if ($normalized === false) {
            return $str;
        }
        return preg_replace('/[\x{0300}-\x{036f}]/u', '', $normalized) ?? $str;
    }

    /**
     * Clean a field key for comparison (remove underscores, lowercase, strip accents).
     */
    private function cleanFieldKey(string $key): string
    {
        return $this->removeAccents(str_replace('_', '', mb_strtolower($key)));
    }
}
