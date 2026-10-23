<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\ServiceOrderInseminationEntity;
use App\Core\ValueObjects\InseminationFilters;

interface IServiceOrderInseminationRepository
{
    public function create(ServiceOrderInseminationEntity $insemination): ServiceOrderInseminationEntity;

    public function save(ServiceOrderInseminationEntity $insemination): void;

    public function lockById(int $id, int $companyId): ?ServiceOrderInseminationEntity;

    /** @return list<ServiceOrderInseminationEntity> live inseminations taken out by any of these consumption lines */
    public function liveByLines(array $lineIds, int $companyId): array;

    public function liveCountByOrder(int $orderId): int;

    /**
     * Live (female, day) pairs of an order among the given ones, as "femaleId|Y-m-d" keys (IA6).
     *
     * @param list<array{0: int, 1: string}> $pairs
     * @return list<string>
     */
    public function liveDuplicates(int $orderId, array $pairs): array;

    /** Clears the optional "not inseminated" note of females just inseminated (IA12). */
    public function clearNotInseminatedReason(int $orderId, array $femaleIds): void;

    /** @param array<int, ?string> $reasons female_caravan_id => NotInseminatedReason value or null */
    public function setNotInseminatedReasons(int $orderId, array $reasons): void;

    /**
     * Every insemination of the order (voided too), with the labels the screens show.
     *
     * @return list<array<string, mixed>>
     */
    public function listForOrder(int $orderId, int $companyId): array;

    /**
     * Straws still expected by the open AI orders: one per female with that semen planned and no live
     * insemination yet (IA5). @return array<int, int> item id => straws
     */
    public function committedStraws(array $itemIds, int $companyId): array;

    public function documentIdOfLine(int $lineId, int $companyId): ?int;

    /** Balance of each item over every semen tank of the company (IA5). @return array<int, string> item id => straws */
    public function tankBalances(array $itemIds, int $companyId): array;

    /**
     * Inseminations of every order of the company, newest first, with the totals of the whole match.
     *
     * @return array{data: list<array<string, mixed>>, total: int, females: int, straws: int}
     */
    public function search(InseminationFilters $filters, int $companyId): array;
}
