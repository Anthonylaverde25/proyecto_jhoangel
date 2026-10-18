<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Core\Entities\ItemEntity;
use App\Core\Entities\ItemProviderLinkEntity;
use App\Core\Exceptions\ItemCatalogException;
use App\Core\Interfaces\IItemRepository;
use App\Core\Interfaces\IProviderRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Replaces the providers of an item with the full list the screen sends (DB4).
 */
final class SyncItemProvidersUseCase
{
    public function __construct(
        private readonly IItemRepository $items,
        private readonly IProviderRepository $providers,
    ) {
    }

    /**
     * @param list<array{provider_id:int, lead_time_days?:?int, is_preferred?:bool}> $rows
     */
    public function __invoke(int $itemId, array $rows, int $companyId): ItemEntity
    {
        $this->items->findById($itemId, $companyId)
            ?? throw new ModelNotFoundException("Item {$itemId} not found.");

        $links = array_map(fn (array $row) => new ItemProviderLinkEntity(
            (int) $row['provider_id'],
            isset($row['lead_time_days']) ? (int) $row['lead_time_days'] : null,
            (bool) ($row['is_preferred'] ?? false),
        ), array_values($rows));

        ItemProviderLinkEntity::assertValidList($links);

        foreach ($links as $link) {
            // L1 / I9: only a provider of the company that sells articles or services.
            $provider = $this->providers->findById($link->getProviderId());
            if ($provider === null || $provider->getCompanyId() !== $companyId) {
                throw new ModelNotFoundException("Provider {$link->getProviderId()} not found.");
            }
            if (!$provider->suppliesItems()) {
                throw ItemCatalogException::providerDoesNotSupplyItems($provider->getName());
            }
        }

        $this->items->syncProviderLinks($itemId, $companyId, $links);

        return $this->items->findById($itemId, $companyId);
    }
}
