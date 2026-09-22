<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\BatchTypeEntity;
use App\Core\Interfaces\IBatchTypeRepository;
use App\Models\BatchType;
use App\Application\Mappers\BatchTypeMapper;

class EloquentBatchTypeRepository implements IBatchTypeRepository
{
    public function findAllActiveByCompany(int $companyId): array
    {
        return BatchType::whereHas('companies', function ($q) use ($companyId) {
                $q->where('companies.id', $companyId)
                  ->where('company_batch_type.is_enabled', true);
            })
            ->with(['companies' => function ($q) use ($companyId) {
                $q->where('companies.id', $companyId);
            }])
            ->where('is_active', true)
            ->get()
            ->map(fn (BatchType $model) => BatchTypeMapper::toEntity($model, $companyId))
            ->toArray();
    }

    public function findById(int $id): ?BatchTypeEntity
    {
        $model = BatchType::find($id);
        return $model ? BatchTypeMapper::toEntity($model) : null;
    }

    public function findByCodeAndCompany(string $code, int $companyId): ?BatchTypeEntity
    {
        $model = BatchType::where('code', $code)
            ->whereHas('companies', function ($q) use ($companyId) {
                $q->where('companies.id', $companyId)
                  ->where('company_batch_type.is_enabled', true);
            })
            ->with(['companies' => function ($q) use ($companyId) {
                $q->where('companies.id', $companyId);
            }])
            ->first();

        return $model ? BatchTypeMapper::toEntity($model, $companyId) : null;
    }

    public function findByCode(string $code): ?BatchTypeEntity
    {
        $model = BatchType::where('code', $code)->first();
        return $model ? BatchTypeMapper::toEntity($model) : null;
    }
}
