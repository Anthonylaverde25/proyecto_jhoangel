<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

/**
 * The deaths of animals, as declared facts.
 */
interface ICaravanDeathRepository
{
    public function save(
        int $companyId,
        int $caravanId,
        string $deathDate,
        int $deathCauseId,
        ?string $observations,
        ?int $fromBatchId,
        int $deathBatchId,
        ?int $movementId,
        ?int $userId
    ): int;

    /**
     * The day each animal of the list died, for the ones registered dead.
     *
     * @param int[] $caravanIds
     * @return array<int, string> caravan id => death date (Y-m-d)
     */
    public function deathDates(array $caravanIds): array;

    /**
     * The bulls of the list working in a service order right now: they leave it through
     * "Reemplazar/retirar toro", which already knows death as a reason.
     *
     * @param int[] $caravanIds
     * @return array<int, string> caravan id => service order code
     */
    public function activeServiceBulls(array $caravanIds): array;
}
