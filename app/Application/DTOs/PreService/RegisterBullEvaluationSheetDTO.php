<?php

declare(strict_types=1);

namespace App\Application\DTOs\PreService;

/**
 * A whole chute session: one acting professional, one date, one sampling round, and every bull
 * that passed through.
 *
 * ADR-12: there is deliberately no protocol number here. The act number is minted by the system
 * and the laboratory report number does not exist yet — the tubes have not left the farm.
 */
final readonly class RegisterBullEvaluationSheetDTO
{
    /**
     * @param list<BullEvaluationSheetLineDTO> $bulls
     */
    public function __construct(
        public int $companyId,
        public int $veterinarianId,
        public string $evaluationDate,
        public int $sampleRound,
        public array $bulls,
        public ?string $dispatchNoteNumber = null,
        public ?string $dispatchedAt = null,
        /** ADR-39: the centre the professional was working with. Optional. */
        public ?array $institution = null,
        /** ADR-40: an intention declared at the chute, not a rule. */
        public ?string $destinationPlan = null,
        public ?string $observations = null,
        public ?int $registeredByUserId = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $bulls = [];

        foreach ((array) ($data['bulls'] ?? []) as $line) {
            $bulls[] = BullEvaluationSheetLineDTO::fromArray((array) $line);
        }

        return new self(
            companyId: (int) ($data['company_id'] ?? 0),
            veterinarianId: (int) ($data['veterinarian_id'] ?? 0),
            evaluationDate: (string) ($data['evaluation_date'] ?? date('Y-m-d')),
            sampleRound: (int) ($data['sample_round'] ?? 1),
            bulls: $bulls,
            dispatchNoteNumber: isset($data['dispatch_note_number']) && $data['dispatch_note_number'] !== null
                ? (string) $data['dispatch_note_number']
                : null,
            dispatchedAt: isset($data['dispatched_at']) && $data['dispatched_at'] !== null
                ? (string) $data['dispatched_at']
                : null,
            institution: isset($data['institution']) && is_array($data['institution'])
                ? $data['institution']
                : null,
            destinationPlan: isset($data['destination_plan']) ? (string) $data['destination_plan'] : null,
            observations: isset($data['observations']) ? (string) $data['observations'] : null,
            registeredByUserId: isset($data['registered_by_user_id']) && $data['registered_by_user_id'] !== null
                ? (int) $data['registered_by_user_id']
                : null
        );
    }

    /**
     * @return list<int>
     */
    public function caravanIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (BullEvaluationSheetLineDTO $line): int => $line->caravanId,
            $this->bulls
        )));
    }

    public function hasAnySample(): bool
    {
        foreach ($this->bulls as $line) {
            if ($line->tookAnySample()) {
                return true;
            }
        }

        return false;
    }
}
