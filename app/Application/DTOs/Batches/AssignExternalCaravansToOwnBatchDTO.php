<?php

declare(strict_types=1);

namespace App\Application\DTOs\Batches;

final readonly class AssignExternalCaravansToOwnBatchDTO
{
    /**
     * @param int[] $caravanIds
     */
    public function __construct(
        public array $caravanIds,
        public int $targetBatchId,
        public int $companyId,
        public ?string $entryDate = null,
        public ?string $observations = null
    ) {
    }
}
