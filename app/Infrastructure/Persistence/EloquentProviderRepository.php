<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\ProviderEntity;
use App\Core\Interfaces\IProviderRepository;
use App\Core\ValueObjects\Cuit;
use App\Models\Provider;
use App\Application\Mappers\ProviderMapper;

use Illuminate\Support\Facades\DB;
use App\Application\Mappers\FarmMapper;

class EloquentProviderRepository implements IProviderRepository
{
    public function findAll(?string $supplies = null): array
    {
        return Provider::with('farms')
            ->withExists('fiscalDocuments')
            ->when($supplies === 'livestock', fn ($query) => $query->where('supplies_livestock', true))
            ->when($supplies === 'items', fn ($query) => $query->where('supplies_items', true))
            ->get()
            ->map(fn (Provider $model) => ProviderMapper::toEntity($model))
            ->toArray();
    }

    public function findById(int $id): ?ProviderEntity
    {
        $model = Provider::with('farms')->withExists('fiscalDocuments')->find($id);
        return $model ? ProviderMapper::toEntity($model) : null;
    }

    public function findByCuit(string $cuit, int $companyId): ?ProviderEntity
    {
        // Explicit company filter: this may run outside a request, without company context.
        $model = Provider::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('cuit', Cuit::fromString($cuit)->value())
            ->first();
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
                    // The farm belongs to the provider's company, never to whatever context is active.
                    $farmModel->company_id = $model->company_id;
                    $farmModel->save();
                }
            }

            return ProviderMapper::toEntity($model->load('farms'));
        });
    }

    public function updateRoles(ProviderEntity $provider): void
    {
        Provider::withoutGlobalScopes()
            ->where('company_id', $provider->getCompanyId())
            ->whereKey($provider->getId())
            ->update([
                'supplies_livestock' => $provider->suppliesLivestock(),
                'supplies_items' => $provider->suppliesItems(),
            ]);
    }

    public function updateDetails(ProviderEntity $provider): void
    {
        Provider::withoutGlobalScopes()
            ->where('company_id', $provider->getCompanyId())
            ->whereKey($provider->getId())
            ->first()
            ?->fill([
                'name' => $provider->getName(),
                'commercial_name' => $provider->getCommercialName(),
                'cuit' => $provider->getCuit(),
                'location' => $provider->getLocation(),
                'email' => $provider->getEmail(),
                'phone' => $provider->getPhone(),
            ])
            ->save();
    }

    public function delete(int $id): bool
    {
        return (bool) Provider::destroy($id);
    }
}
