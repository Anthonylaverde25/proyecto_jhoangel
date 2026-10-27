<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

/**
 * Calves whose sire is not confirmed yet, and the confirmation of their paternity.
 */
interface IPaternityRepository
{
    /**
     * Calves of the company without a sire, with their mother, batch, gestation and service order,
     * and the candidate sires already kept on the gestation.
     *
     * @param int[]|null $calfIds only these calves (pending or not), for a confirmation
     * @return list<array{
     *     calf_id: int, calf_identification: ?string, calf_sex: ?string, birth_date: string,
     *     father_id: ?int, mother_id: int, mother_identification: ?string,
     *     batch_id: ?int, batch_name: ?string, gestation_id: ?int,
     *     service_order_id: ?int, service_order_code: ?string,
     *     gestation_sires: list<array{id: int, identification: string}>
     * }>
     */
    public function findCalves(int $companyId, ?int $serviceOrderId = null, ?string $search = null, ?array $calfIds = null): array;

    /**
     * Stays of every bull in the given orders.
     *
     * @param int[] $serviceOrderIds
     * @return array<int, array<int, array{identification: string, joined_at: ?string, retired_at: ?string}>> order id => bull id => stay
     */
    public function findBullStays(array $serviceOrderIds): array;

    /**
     * Writes the sire on the calf's lineage and marks him as the confirmed sire of the gestation
     * (added when he was not a candidate); the other candidates stay unconfirmed.
     */
    public function confirm(int $calfId, int $fatherId, string $method, ?string $notes, ?int $gestationId): void;
}
