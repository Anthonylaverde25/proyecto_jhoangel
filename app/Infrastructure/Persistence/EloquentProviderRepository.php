<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\ProviderEntity;
use App\Core\Interfaces\IProviderRepository;
use App\Models\Provider;
use App\Application\Mappers\ProviderMapper;

class EloquentProviderRepository implements IProviderRepository
{
    public function findAll(): array
    {
        return Provider::all()
            ->map(fn (Provider $model) => ProviderMapper::toEntity($model))
            ->toArray();
    }

    public function findById(int $id): ?ProviderEntity
    {
        $model = Provider::find($id);
        return $model ? ProviderMapper::toEntity($model) : null;
    }

    public function findByCuit(string $cuit): ?ProviderEntity
    {
        $model = Provider::where('cuit', $cuit)->first();
        return $model ? ProviderMapper::toEntity($model) : null;
    }

    public function save(ProviderEntity $provider): ProviderEntity
    {
        $model = $provider->getId() !== null ? Provider::find($provider->getId()) : null;
        $model = ProviderMapper::toModel($provider, $model);
        $model->save();

        return ProviderMapper::toEntity($model);
    }

    public function delete(int $id): bool
    {
        return (bool) Provider::destroy($id);
    }
}
