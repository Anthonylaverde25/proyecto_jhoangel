<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\BirthOrderEntity;

interface IBirthOrderRepository
{
    /**
     * Persists the order and its roll. A history line is written when the order is new, when its
     * status changes, or when `$metadata` is given.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function save(BirthOrderEntity $order, ?int $actionUserId, ?string $reason = null, ?array $metadata = null): BirthOrderEntity;

    public function findById(int $id, int $companyId): ?BirthOrderEntity;

    public function findByCode(string $code, int $companyId): ?BirthOrderEntity;

    /**
     * @param bool $overdueOnly only the orders with a female past her due date without calving
     * @return BirthOrderEntity[] newest first, without history
     */
    public function list(int $companyId, ?string $status = null, ?string $kind = null, bool $overdueOnly = false): array;

    /**
     * The open birth order where this female is still waiting to calve (PENDING or OVERDUE) for
     * this gestation, or null.
     */
    public function findOpenLineForGestation(int $motherId, int $gestationId, int $companyId): ?BirthOrderEntity;

    /**
     * The highest code already issued with this prefix (e.g. "PA-20260929-"), or null.
     */
    public function lastCodeWithPrefix(string $prefix, int $companyId): ?string;

    /**
     * Females of the list still open (PENDING or OVERDUE) in an open birth order, with that order's code. Only birth
     * orders hold a female: transfer and weaning orders do not, and are not held by them.
     *
     * @param int[] $motherIds
     * @return array<int, string> caravan id => order code
     */
    public function findCommittedMothers(array $motherIds, int $companyId, ?int $exceptOrderId = null): array;

    /**
     * Every female still open (PENDING or OVERDUE) in an open birth order of the company, with that order's code.
     * What the screen that starts an order marks as not available.
     *
     * @return array<int, string> caravan id => order code
     */
    public function openMothers(int $companyId): array;

    /**
     * Each female of the list that belongs to the company: where she is, her sex and her current
     * gestation. What an order is checked against before it takes a female.
     *
     * @param int[] $motherIds
     * @return array<int, array{identification: string, sex: string, batch_id: ?int, gestation_id: ?int}> caravan id => facts
     */
    public function motherFacts(array $motherIds, int $companyId): array;

    /**
     * The gestation loss reason of the company with this code (STILLBORN, ABORTION…), or null.
     */
    public function lossReasonIdByCode(string $code, int $companyId): ?int;

    /**
     * The code of a gestation loss reason of the company, or null.
     */
    public function lossReasonCodeById(int $id, int $companyId): ?string;
}
