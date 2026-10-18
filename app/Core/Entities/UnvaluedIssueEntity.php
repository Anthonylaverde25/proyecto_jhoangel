<?php

declare(strict_types=1);

namespace App\Core\Entities;

/**
 * Units an outbound line took from an unvalued lot (delivery C, model 2.4). Its cost is the lot's
 * once the invoice values it; until then it is null, never a guessed figure (DC3).
 */
final readonly class UnvaluedIssueEntity
{
    public function __construct(
        public ?int $id,
        public int $companyId,
        public int $lotId,
        public int $itemDocumentLineId,
        public string $quantity,
        public ?string $unitCost = null,
        public ?string $totalCost = null,
        public ?int $reversedByLineId = null,
    ) {
    }
}
