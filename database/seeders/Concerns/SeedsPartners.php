<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use App\Core\Enums\PartnerRole;
use App\Models\Partner;
use App\Models\PartnerContact;

/**
 * Seeding a partner never steps over the flow: it is created when missing, gets the roles it lacks
 * (none is removed) and gets a default contact only when it has none. Seeders run without model
 * events, so the company always goes explicitly.
 */
trait SeedsPartners
{
    /**
     * @param array<string, mixed> $attributes
     * @param list<PartnerRole> $roles
     * @param array{name: string, phone: string, email?: ?string, position?: ?string}|null $contact
     */
    private function seedPartner(int $companyId, string $cuit, array $attributes, array $roles, ?array $contact = null): Partner
    {
        $partner = Partner::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId, 'cuit' => $cuit],
            $attributes + ['is_active' => true]
        );

        $this->grantRoles($partner, $roles);
        if ($contact !== null) {
            $this->ensureDefaultContact($partner, $contact);
        }

        return $partner;
    }

    /** @param array{name: string, phone: string, email?: ?string, position?: ?string} $contact */
    private function ensureDefaultContact(Partner $partner, array $contact): void
    {
        if (PartnerContact::query()->where('partner_id', $partner->id)->exists()) {
            return;
        }

        PartnerContact::query()->create($contact + [
            'company_id' => $partner->company_id,
            'partner_id' => $partner->id,
            'is_default' => true,
        ]);
    }

    /** @param list<PartnerRole> $roles */
    private function grantRoles(Partner $partner, array $roles): void
    {
        foreach ($roles as $role) {
            $partner->roles()->firstOrCreate(
                ['role' => $role->value],
                ['company_id' => $partner->company_id]
            );
        }
    }
}
