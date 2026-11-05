<?php

declare(strict_types=1);

namespace App\Application\DTOs;

/** How the caravan stood after a weighing recorded through an AI client. */
final readonly class AiWeighingResultDTO
{
    /** @param list<string> $warnings the warnings the user accepted */
    public function __construct(
        public int $caravanId,
        public string $identification,
        public ?float $previousWeight,
        public ?string $previousDate,
        public float $newWeight,
        public ?float $deltaKg,
        public ?float $dailyChangeKg,
        public bool $becomesCurrent,
        public string $weighingDate,
        public array $warnings,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'caravan_id' => $this->caravanId,
            'identification' => $this->identification,
            'previous_weight' => $this->previousWeight,
            'previous_date' => $this->previousDate,
            'new_weight' => $this->newWeight,
            'delta_kg' => $this->deltaKg,
            'daily_change_kg' => $this->dailyChangeKg,
            'becomes_current' => $this->becomesCurrent,
            'weighing_date' => $this->weighingDate,
            'warnings' => $this->warnings,
        ];
    }
}
