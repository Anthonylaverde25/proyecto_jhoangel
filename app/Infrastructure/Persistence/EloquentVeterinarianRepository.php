<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\VeterinarianMapper;
use App\Core\Entities\VeterinarianEntity;
use App\Core\Interfaces\IVeterinarianRepository;
use App\Models\Veterinarian;
use App\Models\VeterinarianBatchAssignment;

class EloquentVeterinarianRepository implements IVeterinarianRepository
{
    /**
     * @return array<VeterinarianEntity>
     */
    public function findAll(int $companyId, bool $activeOnly = true): array
    {
        $query = Veterinarian::query()
            ->where('company_id', $companyId);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->orderBy('name')
            ->get()
            ->map(static fn (Veterinarian $model): VeterinarianEntity => VeterinarianMapper::toDomain($model))
            ->all();
    }

    public function findById(int $id, int $companyId): ?VeterinarianEntity
    {
        $model = Veterinarian::query()
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        return $model ? VeterinarianMapper::toDomain($model) : null;
    }

    public function findByLicenseNumber(string $licenseNumber, int $companyId): ?VeterinarianEntity
    {
        $model = Veterinarian::query()
            ->where('license_number', $licenseNumber)
            ->where('company_id', $companyId)
            ->first();

        return $model ? VeterinarianMapper::toDomain($model) : null;
    }

    public function findByUserId(int $userId, int $companyId): ?VeterinarianEntity
    {
        $model = Veterinarian::query()
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->first();

        return $model ? VeterinarianMapper::toDomain($model) : null;
    }

    public function save(VeterinarianEntity $veterinarian): VeterinarianEntity
    {
        $attributes = VeterinarianMapper::toPersistence($veterinarian);

        if ($veterinarian->getId() !== null) {
            $model = Veterinarian::query()->findOrFail($veterinarian->getId());
            $model->update($attributes);
        } else {
            $model = Veterinarian::create($attributes);
        }

        return VeterinarianMapper::toDomain($model->fresh());
    }

    /**
     * @return list<int>
     */
    public function findActiveBatchIds(int $veterinarianId, int $companyId): array
    {
        return VeterinarianBatchAssignment::query()
            ->where('company_id', $companyId)
            ->where('veterinarian_id', $veterinarianId)
            ->whereNull('unassigned_at')
            ->pluck('batch_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
