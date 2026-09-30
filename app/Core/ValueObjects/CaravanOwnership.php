<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * Who holds a caravan, across every company of the tenant.
 *
 * A deliberately thin read model: answering "is this tag already registered, and by whom?"
 * for a whole chute session must not hydrate full entities with gestations and lineage.
 */
final readonly class CaravanOwnership
{
    public function __construct(
        public int $caravanId,
        public string $identification,
        public int $companyId,
        public ?int $batchId,
        public ?string $batchName,
    ) {
    }
}
