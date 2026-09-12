<?php

declare(strict_types=1);

namespace App\Application\DTOs\Veterinary;

/**
 * Everything the professional records for one bull in a single pass through the chute:
 * andrological biometry plus the samples taken on the spot.
 */
final readonly class PortalBullEvaluationLineDTO
{
    /**
     * @param list<ProtocolSampleLineDTO> $samples
     */
    public function __construct(
        public int $caravanId,
        public array $samples = [],
        public ?float $scrotalCircumferenceCm = null,
        public ?float $bodyConditionScore = null,
        public ?string $aplomoNotes = null,
        public string $libido = 'MEDIA',
        public ?string $observations = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $caravanId = (int) ($data['caravan_id'] ?? 0);
        $samples = [];

        foreach ((array) ($data['samples'] ?? []) as $sample) {
            $sample = (array) $sample;
            $sample['caravan_id'] = $caravanId;
            $samples[] = ProtocolSampleLineDTO::fromArray($sample);
        }

        return new self(
            caravanId: $caravanId,
            samples: $samples,
            scrotalCircumferenceCm: isset($data['scrotal_circumference_cm']) && $data['scrotal_circumference_cm'] !== null
                ? (float) $data['scrotal_circumference_cm']
                : null,
            bodyConditionScore: isset($data['body_condition_score']) && $data['body_condition_score'] !== null
                ? (float) $data['body_condition_score']
                : null,
            aplomoNotes: isset($data['aplomo_notes']) ? (string) $data['aplomo_notes'] : null,
            libido: (string) ($data['libido'] ?? 'MEDIA'),
            observations: isset($data['observations']) ? (string) $data['observations'] : null
        );
    }

    public function hasBiometry(): bool
    {
        return $this->scrotalCircumferenceCm !== null
            || $this->bodyConditionScore !== null
            || ($this->aplomoNotes !== null && $this->aplomoNotes !== '');
    }
}
