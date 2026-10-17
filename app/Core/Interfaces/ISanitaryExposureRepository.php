<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\SanitaryExposureAlertEntity;
use App\Core\ValueObjects\SanitaryExposure\BullParticipation;
use App\Core\ValueObjects\SanitaryExposure\ContagiousFinding;
use App\Core\ValueObjects\SanitaryExposure\ExposureOverlap;
use App\Core\ValueObjects\SanitaryExposure\FemaleExposure;
use DateTimeImmutable;

interface ISanitaryExposureRepository
{
    /**
     * Every stay of the bull in a started service order.
     *
     * @return list<BullParticipation>
     */
    public function bullParticipations(int $bullId, int $companyId): array;

    /**
     * Exposure windows of the females of the given orders.
     *
     * @param int[] $serviceOrderIds
     * @return list<FemaleExposure>
     */
    public function femaleExposures(array $serviceOrderIds): array;

    /**
     * @return array{id: int, status: string}|null
     */
    public function findByFinding(int $bullId, string $pathogenCode, DateTimeImmutable $detectedOn): ?array;

    public function create(int $companyId, int $bullId, ContagiousFinding $finding, string $detail): int;

    public function updateDetail(int $alertId, string $detail): void;

    /**
     * Add the females not yet in the alert and refresh the overlap of those already there.
     *
     * @param list<ExposureOverlap> $overlaps
     */
    public function syncFemales(int $alertId, int $companyId, array $overlaps): void;

    public function bullTag(int $bullId): string;

    /**
     * @return list<SanitaryExposureAlertEntity>
     */
    public function list(int $companyId, ?string $status): array;

    public function find(int $alertId, int $companyId): ?SanitaryExposureAlertEntity;

    /**
     * @param int[] $femaleIds
     * @return int How many females were marked.
     */
    public function markReviewed(int $alertId, array $femaleIds, ?int $userId, ?string $note, DateTimeImmutable $at): int;

    public function resolve(int $alertId, ?int $userId, string $resolution, DateTimeImmutable $at): void;

    /**
     * Open alerts the given females belong to.
     *
     * @param int[] $femaleIds
     * @return array<int, list<array{alert_id: int, bull_tag: string, pathogen: string, reviewed: bool}>>
     */
    public function openExposuresForFemales(array $femaleIds, int $companyId): array;

    /**
     * Open alerts with females currently in the batch.
     *
     * @return list<array{alert_id: int, bull_tag: string, pathogen: string, count: int}>
     */
    public function openAlertsInBatch(int $batchId, int $companyId): array;
}
