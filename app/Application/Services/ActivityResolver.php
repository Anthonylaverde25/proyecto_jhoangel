<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\Activity;

/**
 * Result DTO for Activity resolution.
 */
final class ActivityResolutionResult
{
    public function __construct(
        public readonly ?int $activityId,
        public readonly ?string $activityName,
        public readonly ?string $activityCode,
        public readonly float $confidence,
        public readonly bool $isResolved
    ) {
    }
}

/**
 * Deterministic application service to resolve raw OCR/text cells
 * into canonical Activity ID, Name, and Code.
 */
class ActivityResolver
{
    /**
     * Common production synonyms and aliases mapped to standard activity codes.
     */
    private const KNOWN_SYNONYMS = [
        'cria'              => 'CRIA',
        'cría'              => 'CRIA',
        'breeding'          => 'CRIA',
        'cria vacuna'       => 'CRIA',
        'cría vacuna'       => 'CRIA',
        'cria comercial'    => 'CRIA',
        'cría comercial'    => 'CRIA',
        'rodeo cria'        => 'CRIA',
        'rodeo cría'        => 'CRIA',
        'recria'            => 'RECRIA',
        'recría'            => 'RECRIA',
        're-cria'           => 'RECRIA',
        're-cría'           => 'RECRIA',
        'rearing'           => 'RECRIA',
        'growing'           => 'RECRIA',
        'recria corral'     => 'RECRIA',
        'recría corral'     => 'RECRIA',
        'invernada'         => 'INVERNADA',
        'engorde'           => 'INVERNADA',
        'feedlot'           => 'INVERNADA',
        'feed lot'          => 'INVERNADA',
        'terminacion'       => 'INVERNADA',
        'terminación'       => 'INVERNADA',
        'fattening'         => 'INVERNADA',
        'interna'           => 'INTERNAL',
        'actividad interna' => 'INTERNAL',
        'internal'          => 'INTERNAL',
    ];

    /**
     * Resolve raw input string (e.g. from OCR or handwriting) to canonical Activity.
     *
     * @param string|null $rawInput
     * @param int|null $companyId Optional company ID for scoping (future multi-tenant scoping if needed)
     * @return ActivityResolutionResult
     */
    public function resolve(?string $rawInput, ?int $companyId = null): ActivityResolutionResult
    {
        if ($rawInput === null) {
            return new ActivityResolutionResult(null, null, null, 0.0, false);
        }

        $trimmed = trim($rawInput);
        if ($trimmed === '') {
            return new ActivityResolutionResult(null, null, null, 0.0, false);
        }

        $normalized = $this->normalizeString($trimmed);

        // 1. Check direct match in known synonyms
        if (isset(self::KNOWN_SYNONYMS[$normalized])) {
            $targetCode = self::KNOWN_SYNONYMS[$normalized];
            $activity = Activity::where('code', $targetCode)->first();
            if ($activity) {
                return new ActivityResolutionResult(
                    activityId: $activity->id,
                    activityName: $activity->name,
                    activityCode: $activity->code,
                    confidence: 1.0,
                    isResolved: true
                );
            }
        }

        // 2. Fetch all activities from master catalog
        $allActivities = Activity::all();

        // 3. Exact matching on Activity Code (case-insensitive)
        $upperInput = mb_strtoupper($trimmed);
        foreach ($allActivities as $act) {
            if (mb_strtoupper($act->code) === $upperInput) {
                return new ActivityResolutionResult(
                    activityId: $act->id,
                    activityName: $act->name,
                    activityCode: $act->code,
                    confidence: 1.0,
                    isResolved: true
                );
            }
        }

        // 4. Normalized exact matching on Activity Name
        foreach ($allActivities as $act) {
            if ($this->normalizeString($act->name) === $normalized) {
                return new ActivityResolutionResult(
                    activityId: $act->id,
                    activityName: $act->name,
                    activityCode: $act->code,
                    confidence: 1.0,
                    isResolved: true
                );
            }
        }

        // 5. Prefix / Substring matching
        foreach ($allActivities as $act) {
            $normActName = $this->normalizeString($act->name);
            if (str_starts_with($normalized, $normActName) || str_ends_with($normalized, $normActName)) {
                return new ActivityResolutionResult(
                    activityId: $act->id,
                    activityName: $act->name,
                    activityCode: $act->code,
                    confidence: 0.9,
                    isResolved: true
                );
            }
        }

        // 6. Fuzzy matching with Levenshtein distance <= 2
        $bestMatch = null;
        $lowestDist = 999;
        foreach ($allActivities as $act) {
            $normActName = $this->normalizeString($act->name);
            $dist = levenshtein($normalized, $normActName);
            if ($dist <= 2 && $dist < $lowestDist) {
                $lowestDist = $dist;
                $bestMatch = $act;
            }
        }

        if ($bestMatch !== null) {
            return new ActivityResolutionResult(
                activityId: $bestMatch->id,
                activityName: $bestMatch->name,
                activityCode: $bestMatch->code,
                confidence: 0.8,
                isResolved: true
            );
        }

        return new ActivityResolutionResult(null, null, null, 0.0, false);
    }

    /**
     * Normalize string by lowercasing, removing diacritics, and stripping non-alphanumeric chars.
     */
    private function normalizeString(string $input): string
    {
        $lower = mb_strtolower(trim($input), 'UTF-8');

        // Replace accented characters
        $unaccented = strtr($lower, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n'
        ]);

        // Strip non-alphanumeric except spaces
        $clean = preg_replace('/[^a-z0-9\s]/u', '', $unaccented) ?? '';

        return trim(preg_replace('/\s+/', ' ', $clean));
    }
}
