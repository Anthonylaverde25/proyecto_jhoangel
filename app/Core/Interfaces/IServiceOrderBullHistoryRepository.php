<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

/**
 * Read model of the bulls of one service order: who served, when and why each one joined and
 * left, and the gestations each one is the confirmed or a candidate sire of.
 */
interface IServiceOrderBullHistoryRepository
{
    /**
     * @return array{
     *     id: int, code: string, status: string, service_method: string, service_type: ?string,
     *     batch_id: int, batch_name: ?string, start_date: ?string, end_date: ?string
     * }|null null when the order does not belong to the company
     */
    public function findOrder(int $serviceOrderId, int $companyId): ?array;

    /**
     * Every stay of a bull in the order, in the order they joined.
     *
     * @return list<array{
     *     bull_id: int, identification: string, category: ?string, breed: ?string,
     *     status: string, joined_at: ?string, retired_at: ?string,
     *     sanitary_status_at_entry: ?string, scrotal_circumference: ?float, service_capacity: ?string,
     *     exit_reason: ?string, exit_notes: ?string, exit_destination_batch: ?string
     * }>
     */
    public function findBullStays(int $serviceOrderId): array;

    /**
     * @return list<array{
     *     retired_bull_id: int, replacement_bull_id: int, date: string, reason: string,
     *     notes: ?string, destination_batch: ?string
     * }>
     */
    public function findReplacements(int $serviceOrderId): array;

    /**
     * Sires recorded on the gestations of the order (confirmed or candidates).
     *
     * @return list<array{bull_id: int, female_id: int, female_identification: string, gestation_id: int, is_confirmed: bool, start_date: ?string}>
     */
    public function findGestationSires(int $serviceOrderId): array;
}
