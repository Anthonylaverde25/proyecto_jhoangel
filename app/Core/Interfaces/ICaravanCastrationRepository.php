<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Enums\CastrationMethod;
use App\Core\ValueObjects\CastrationRecord;

interface ICaravanCastrationRepository
{
    /**
     * Returns the active (non-voided) castration record for each caravan ID provided.
     *
     * @param list<int> $caravanIds
     * @return array<int, CastrationRecord> caravanId => active CastrationRecord
     */
    public function activeByCaravans(array $caravanIds): array;

    /**
     * Persists a castration record.
     */
    public function save(
        int $companyId,
        int $caravanId,
        string $castratedOn,
        ?CastrationMethod $method,
        ?string $notes,
        ?int $fromCategoryId,
        ?int $userId
    ): int;

    /**
     * Finds an active castration record by its ID.
     *
     * @return array{
     *     id: int,
     *     company_id: int,
     *     caravan_id: int,
     *     castrated_on: string,
     *     method: ?CastrationMethod,
     *     notes: ?string,
     *     from_category_id: ?int,
     *     from_category_code: ?string
     * }|null
     */
    public function findActive(int $castrationId): ?array;

    /**
     * Marks an active castration as voided.
     */
    public function void(int $castrationId, ?string $reason, ?int $userId): void;

    /**
     * Living male candidates in a batch: TERNERO without active castration, and TORITO.
     *
     * @return list<array{
     *     id: int,
     *     caravan_number: string,
     *     tag_number: ?string,
     *     category_id: ?int,
     *     category_code: ?string,
     *     category_name: ?string,
     *     birth_date: ?string,
     *     blocked_by: ?string
     * }>
     */
    public function candidatesByBatch(int $companyId, int $batchId): array;

    /**
     * Total count of castration candidates in a batch.
     */
    public function countCandidatesByBatch(int $companyId, int $batchId): int;

    /**
     * Maps caravan ID => active service order code for bulls currently active in a service order (CS4).
     *
     * @param list<int> $caravanIds
     * @return array<int, string>
     */
    public function activeServiceBulls(array $caravanIds): array;
}
