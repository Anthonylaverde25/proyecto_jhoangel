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
        return BatchType::byCompany($companyId)
            ->active()
            ->get()
            ->map(fn (BatchType $model) => BatchTypeMapper::toEntity($model))
            ->toArray();
    }

    public function findById(int $id): ?BatchTypeEntity
    {
        $model = BatchType::find($id);
        return $model ? BatchTypeMapper::toEntity($model) : null;
    }

    public function findByCodeAndCompany(string $code, int $companyId): ?BatchTypeEntity
    {
        $model = BatchType::byCompany($companyId)
            ->byCode($code)
            ->first();
        return $model ? BatchTypeMapper::toEntity($model) : null;
    }

    public function findByCode(string $code): ?BatchTypeEntity
    {
        $model = BatchType::where('code', $code)->first();
        return $model ? BatchTypeMapper::toEntity($model) : null;
    }
}

