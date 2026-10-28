<?php

declare(strict_types=1);

namespace Tests\Feature\Partners;

use App\Core\ValueObjects\Cuit;
use App\Models\Partner;
use Tests\Feature\ItemCatalog\ItemCatalogTestCase;

/**
 * Two companies of one tenant, with the catalog helpers. The test tenant persists between runs,
 * so every partner gets a CUIT not used yet.
 */
abstract class PartnerTestCase extends ItemCatalogTestCase
{
    /** @param array<string, mixed> $payload */
    protected function createPartner(array $payload = []): array
    {
        return $this->apiAs('POST', '/partners', $payload + ['name' => 'Tercero ' . random_int(1, 99_999), 'cuit' => $this->freshCuit()])
            ->assertStatus(201)->json();
    }

    protected function freshCuit(): string
    {
        do {
            $ten = '30' . str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
            $expected = Cuit::fromString($ten . '0')->expectedCheckDigit();
            $digits = $ten . $expected;
        } while ($expected === null || Partner::withoutGlobalScopes()->where('cuit', $digits)->exists());

        return $digits;
    }
}
