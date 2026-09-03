<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\PathogenMapper;
use App\Core\Entities\PathogenEntity;
use App\Core\Interfaces\IPathogenRepository;
use App\Models\Pathogen;

class EloquentPathogenRepository implements IPathogenRepository
{
    /**
     * @return array<PathogenEntity>
     */
    public function findAll(): array
    {
        return Pathogen::orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn (Pathogen $model) => PathogenMapper::toDomain($model))
            ->all();
    }

    public function findById(int $id): ?PathogenEntity
    {
        $model = Pathogen::find($id);

        return $model ? PathogenMapper::toDomain($model) : null;
    }

    public function findByCode(string $code): ?PathogenEntity
    {
        $model = Pathogen::where('code', $code)->first();

        return $model ? PathogenMapper::toDomain($model) : null;
    }
}
