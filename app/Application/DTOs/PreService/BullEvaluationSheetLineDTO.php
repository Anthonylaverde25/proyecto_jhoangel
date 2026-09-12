<?php

declare(strict_types=1);

namespace App\Application\DTOs\PreService;

/**
 * One row of the chute sheet: what was measured on the animal and which tubes were drawn.
 *
 * The sheet records that a sample was TAKEN; the result comes back from the laboratory later
 * and is loaded through the digitisation wizard or the lab-results entry.
 */
final readonly class BullEvaluationSheetLineDTO
{
    public function __construct(
        public int $caravanId,
        public ?float $scrotalCircumferenceCm = null,
        public ?float $bodyConditionScore = null,
        public string $libido = 'MEDIA',
        public ?string $aplomoNotes = null,
        public ?string $observations = null,
        public bool $prepuceScrape = false,
        public ?string $prepuceScrapeTube = null,
        public bool $bloodSerology = false,
        public ?string $bloodSerologyTube = null,
        /**
         * ADR-26: the day THIS animal was actually worked. One act may span two chute days, and
         * ADR-4 compares negative rounds by date — read from the act, that comparison lies.
         * Null falls back to the act's opening date.
         */
        public ?string $extractedOn = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            caravanId: (int) ($data['caravan_id'] ?? 0),
            scrotalCircumferenceCm: isset($data['scrotal_circumference_cm']) && $data['scrotal_circumference_cm'] !== null
                ? (float) $data['scrotal_circumference_cm']
                : null,
            bodyConditionScore: isset($data['body_condition_score']) && $data['body_condition_score'] !== null
                ? (float) $data['body_condition_score']
                : null,
            libido: (string) ($data['libido'] ?? 'MEDIA'),
            aplomoNotes: isset($data['aplomo_notes']) ? (string) $data['aplomo_notes'] : null,
            observations: isset($data['observations']) ? (string) $data['observations'] : null,
            prepuceScrape: (bool) ($data['prepuce_scrape'] ?? false),
            prepuceScrapeTube: isset($data['prepuce_scrape_tube']) ? (string) $data['prepuce_scrape_tube'] : null,
            bloodSerology: (bool) ($data['blood_serology'] ?? false),
            bloodSerologyTube: isset($data['blood_serology_tube']) ? (string) $data['blood_serology_tube'] : null,
            extractedOn: isset($data['extracted_on']) && $data['extracted_on'] !== null
                ? (string) $data['extracted_on']
                : null
        );
    }

    public function hasBiometry(): bool
    {
        return $this->scrotalCircumferenceCm !== null
            || $this->bodyConditionScore !== null
            || ($this->aplomoNotes !== null && $this->aplomoNotes !== '');
    }

    public function tookAnySample(): bool
    {
        return $this->prepuceScrape || $this->bloodSerology;
    }

    /**
     * @return array<string, ?string> Assay type => tube number, only for the ones actually drawn.
     */
    public function assays(): array
    {
        $assays = [];

        if ($this->prepuceScrape) {
            $assays['PREPUCE_SCRAPE'] = $this->prepuceScrapeTube;
        }

        if ($this->bloodSerology) {
            $assays['BLOOD_SEROLOGY'] = $this->bloodSerologyTube;
        }

        return $assays;
    }
}
