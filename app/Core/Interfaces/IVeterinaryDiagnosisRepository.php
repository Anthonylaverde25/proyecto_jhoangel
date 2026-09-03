<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\VeterinaryDiagnosisEntity;
use DateTimeImmutable;

interface IVeterinaryDiagnosisRepository
{
    public function save(VeterinaryDiagnosisEntity $diagnosis): VeterinaryDiagnosisEntity;

    public function findById(int $id): ?VeterinaryDiagnosisEntity;

    /**
     * @return array<VeterinaryDiagnosisEntity>
     */
    public function findByCaravanId(int $caravanId, bool $activeOnly = false): array;

    /**
     * @return array<VeterinaryDiagnosisEntity>
     */
    public function findActiveByCaravanId(int $caravanId): array;

    public function resolve(int $id, DateTimeImmutable $resolutionDate, ?string $notes = null): bool;
}
