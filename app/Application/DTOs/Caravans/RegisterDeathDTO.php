<?php

declare(strict_types=1);

namespace App\Application\DTOs\Caravans;

/**
 * One or several animals that died on the same day, of the same cause.
 */
final readonly class RegisterDeathDTO
{
    /**
     * @param list<int> $caravanIds
     */
    public function __construct(
        public array $caravanIds,
        public string $deathDate,
        public int $deathCauseId,
        public ?string $observations,
        public ?int $deathBatchId,
        public int $companyId,
        public ?int $userId
    ) {
    }
}
