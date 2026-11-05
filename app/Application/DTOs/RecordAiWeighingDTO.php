<?php

declare(strict_types=1);

namespace App\Application\DTOs;

/** A control weighing of one caravan asked for through an AI client. Type and source are not asked: the use case fixes them. */
final readonly class RecordAiWeighingDTO
{
    public function __construct(
        public int $caravanId,
        public float $weight,
        public string $date,
        public ?string $notes = null,
        public bool $acknowledgeWarnings = false,
    ) {
    }
}
