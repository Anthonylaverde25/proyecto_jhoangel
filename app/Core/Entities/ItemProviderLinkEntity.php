<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Exceptions\ItemCatalogException;

/**
 * A provider that sells an item. Name and CUIT are read-only context for the response.
 */
final class ItemProviderLinkEntity
{
    public function __construct(
        private readonly int $providerId,
        private readonly ?int $leadTimeDays = null,
        private readonly bool $isPreferred = false,
        private readonly ?string $providerName = null,
        private readonly ?string $providerCuit = null,
    ) {
        if ($leadTimeDays !== null && ($leadTimeDays < 0 || $leadTimeDays > 365)) {
            throw ItemCatalogException::invalidLeadTime();
        }
    }

    /**
     * L3 and I6 over the whole list the screen sends (DB4).
     *
     * @param list<self> $links
     */
    public static function assertValidList(array $links): void
    {
        $ids = array_map(fn (self $link) => $link->providerId, $links);
        if (count($ids) !== count(array_unique($ids))) {
            throw ItemCatalogException::duplicateProvider();
        }
        if (count(array_filter($links, fn (self $link) => $link->isPreferred)) > 1) {
            throw ItemCatalogException::severalPreferred();
        }
    }

    public function getProviderId(): int { return $this->providerId; }
    public function getLeadTimeDays(): ?int { return $this->leadTimeDays; }
    public function isPreferred(): bool { return $this->isPreferred; }
    public function getProviderName(): ?string { return $this->providerName; }
    public function getProviderCuit(): ?string { return $this->providerCuit; }
}
