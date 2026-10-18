<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\ValueObjects\ItemFilters;
use App\Core\Entities\ItemEntity;
use App\Core\Entities\ItemPresentationEntity;
use App\Core\Entities\ItemProviderLinkEntity;

interface IItemRepository
{
    /** @return list<ItemEntity> without presentations or links, with their counts */
    public function search(ItemFilters $filters, int $companyId): array;

    /** With presentations and provider links. */
    public function findById(int $id, int $companyId): ?ItemEntity;

    public function findByCode(string $code, int $companyId): ?ItemEntity;

    /** Saves an existing item, or a new one that already carries its code. */
    public function save(ItemEntity $item): ItemEntity;

    /** Saves a new item under the next ART-/SRV- code of the company, retrying once on a clash. */
    public function saveWithGeneratedCode(ItemEntity $item): ItemEntity;

    public function findPresentation(int $presentationId, int $itemId): ?ItemPresentationEntity;

    public function presentationNameExists(int $itemId, string $name, ?int $exceptId = null): bool;

    public function savePresentation(ItemPresentationEntity $presentation): ItemPresentationEntity;

    /**
     * Replaces every provider link of the item (DB4).
     *
     * @param list<ItemProviderLinkEntity> $links
     */
    public function syncProviderLinks(int $itemId, int $companyId, array $links): void;

    /** Number of items a provider is linked to (DB5). */
    public function countLinksOfProvider(int $providerId, int $companyId): int;
}
