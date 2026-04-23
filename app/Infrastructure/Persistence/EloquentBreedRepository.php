<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\BreedEntity;
use App\Core\Interfaces\IBreedRepository;
use App\Models\Breed;

final class EloquentBreedRepository implements IBreedRepository
{
    public function findByNameOrCreate(string $name): BreedEntity
    {
        $normalizedName = ucfirst(mb_strtolower(trim($name)));
        
        $model = Breed::firstOrCreate(['name' => $normalizedName]);

        return new BreedEntity(
            id: $model->id,
            name: $model->name
        );
    }

    public function findById(int $id): ?BreedEntity
    {
        $model = Breed::find($id);

        if (!$model) {
            return null;
        }

        return new BreedEntity(
            id: $model->id,
            name: $model->name
        );
    }

    public function getAll(): array
    {
        $models = Breed::orderBy('name')->get();
        $entities = [];

        foreach ($models as $model) {
            $entities[] = new BreedEntity(
                id: $model->id,
                name: $model->name
            );
        }

        return $entities;
    }
}
