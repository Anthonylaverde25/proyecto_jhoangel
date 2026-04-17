<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\ProviderEntity;
use App\Core\Interfaces\IProviderRepository;
use App\Models\Provider;
use App\Application\Mappers\ProviderMapper;

use Illuminate\Support\Facades\DB;
use App\Application\Mappers\FarmMapper;

class EloquentProviderRepository implements IProviderRepository
{
    public function findAll(): array
    {
        return Provider::with('farms')->get()
            ->map(fn (Provider $model) => ProviderMapper::toEntity($model))
            ->toArray();
    }

    public function findById(int $id): ?ProviderEntity
    {
        $model = Provider::with('farms')->find($id);
        return $model ? ProviderMapper::toEntity($model) : null;
    }

    public function findByCuit(string $cuit): ?ProviderEntity
    {
        $model = Provider::where('cuit', $cuit)->first();
        return $model ? ProviderMapper::toEntity($model) : null;
    }

    public function save(ProviderEntity $provider): ProviderEntity
    {
        return DB::transaction(function () use ($provider) {
            $model = $provider->getId() !== null ? Provider::find($provider->getId()) : null;
            $model = ProviderMapper::toModel($provider, $model);
            $model->save();

            // Sincronizar establacimientos (Farms)
            if (!empty($provider->getFarms())) {
                foreach ($provider->getFarms() as $farmEntity) {
                    $farmModel = FarmMapper::toModel($farmEntity);
                    $farmModel->provider_id = $model->id; // Vincular al nuevo proveedor
                    $farmModel->save();
                }
            }

            return ProviderMapper::toEntity($model->load('farms'));
        });
    }

    public function delete(int $id): bool
    {
        return (bool) Provider::destroy($id);
    }
}
