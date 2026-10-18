<?php

declare(strict_types=1);

namespace App\Application\UseCases\Providers;

use App\Core\Entities\ProviderEntity;
use App\Core\Exceptions\ItemCatalogException;
use App\Core\Interfaces\IItemRepository;
use App\Core\Interfaces\IProviderRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * What a provider sells (D1). A role is not taken away while something depends on it (DB5):
 * item links need "sells items", origin farms need "sells livestock".
 */
final class UpdateProviderRolesUseCase
{
    public function __construct(
        private readonly IProviderRepository $providers,
        private readonly IItemRepository $items,
    ) {
    }

    public function __invoke(int $id, bool $suppliesLivestock, bool $suppliesItems, int $companyId): ProviderEntity
    {
        $provider = $this->providers->findById($id);
        if ($provider === null || $provider->getCompanyId() !== $companyId) {
            throw new ModelNotFoundException("Provider {$id} not found.");
        }

        if (!$suppliesItems && $provider->suppliesItems()) {
            $links = $this->items->countLinksOfProvider($id, $companyId);
            if ($links > 0) {
                throw ItemCatalogException::providerLinkedToItems($links);
            }
        }
        if (!$suppliesLivestock && $provider->suppliesLivestock() && count($provider->getFarms()) > 0) {
            throw ItemCatalogException::providerHasFarms(count($provider->getFarms()));
        }

        $provider->changeRoles($suppliesLivestock, $suppliesItems);
        $this->providers->updateRoles($provider);

        return $this->providers->findById($id);
    }
}
