<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Core\Entities\WarehouseEntity;
use App\Core\ValueObjects\ConsumableBatch;

/**
 * What the rules say about a consumption an AI client asks for, before anything is written.
 */
final readonly class AiConsumptionEvaluation
{
    /**
     * @param list<string> $blockers cannot be accepted
     * @param list<string> $warnings stop the write until the user accepts them
     */
    public function __construct(
        public array $blockers,
        public array $warnings,
        public ?ResolvedLine $line,
        public ?ConsumableBatch $batch,
        public ?WarehouseEntity $warehouse,
        public ?int $headsTreated,
    ) {
    }
}
