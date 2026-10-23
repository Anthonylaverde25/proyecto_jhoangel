<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\SemenUnidentifiedEntryEntity;

interface ISemenUnidentifiedRepository
{
    public function create(SemenUnidentifiedEntryEntity $entry): SemenUnidentifiedEntryEntity;

    public function save(SemenUnidentifiedEntryEntity $entry): void;

    public function findByLine(int $lineId, int $companyId): ?SemenUnidentifiedEntryEntity;

    public function lockById(int $id, int $companyId): ?SemenUnidentifiedEntryEntity;

    /** @return list<array{id: int, lot_id: int, quantity: string}> identifications still standing */
    public function identifications(int $entryId, int $companyId): array;

    public function addIdentification(int $entryId, int $lotId, string $quantity, ?int $userId, int $companyId): void;

    public function voidIdentifications(int $entryId, int $companyId): void;

    /** What is still pending in one tank: [entered without lot, left without lot], both positive. @return array{0: string, 1: string} */
    public function pendingSplit(int $itemId, int $warehouseId, int $companyId): array;

    /** @return array<int, string> signed pending quantity by warehouse id (SL3) */
    public function pendingByWarehouse(int $itemId, int $companyId): array;

    /**
     * Open entries with their origin, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listOpen(int $companyId, ?int $itemId, ?int $warehouseId): array;
}
