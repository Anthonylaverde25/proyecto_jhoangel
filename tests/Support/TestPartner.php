<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Enums\PartnerRole;
use App\Models\Partner;
use App\Models\PartnerContact;

/**
 * Creates partners in tests from the attributes the provider tests always used: `supplies_livestock`
 * and `supplies_items` become roles (livestock by default, as the old column did) and a `phone`,
 * with its `email`, becomes the default contact. `roles` takes PartnerRole cases directly.
 */
final class TestPartner
{
    /** @param array<string, mixed> $attributes */
    public static function create(array $attributes): Partner
    {
        [$partnerAttributes, $roles, $contact] = self::split($attributes);

        $partner = Partner::withoutGlobalScopes()->create($partnerAttributes);
        self::complete($partner, $roles, $contact);

        return $partner;
    }

    /**
     * Looks inside the active company, as `Provider::firstOrCreate` did: another company's partner
     * with the same CUIT is not this one.
     *
     * @param array<string, mixed> $keys
     * @param array<string, mixed> $values
     */
    public static function firstOrCreate(array $keys, array $values = []): Partner
    {
        [$partnerValues, $roles, $contact] = self::split($values);

        $partner = Partner::query()->firstOrCreate($keys, $partnerValues);
        if ($partner->wasRecentlyCreated) {
            self::complete($partner, $roles, $contact);
        }

        return $partner;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array{0: array<string, mixed>, 1: list<PartnerRole>, 2: array<string, mixed>|null}
     */
    private static function split(array $attributes): array
    {
        $roles = $attributes['roles'] ?? array_values(array_filter([
            ($attributes['supplies_livestock'] ?? true) ? PartnerRole::SUPPLIER_LIVESTOCK : null,
            ($attributes['supplies_items'] ?? false) ? PartnerRole::SUPPLIER_ITEMS : null,
        ]));

        $phone = $attributes['phone'] ?? null;
        $contact = is_string($phone) && trim($phone) !== ''
            ? ['name' => 'Principal', 'phone' => $phone, 'email' => $attributes['email'] ?? null]
            : null;

        unset($attributes['roles'], $attributes['supplies_livestock'], $attributes['supplies_items'], $attributes['phone'], $attributes['email']);

        return [$attributes, $roles, $contact];
    }

    /**
     * @param list<PartnerRole> $roles
     * @param array<string, mixed>|null $contact
     */
    private static function complete(Partner $partner, array $roles, ?array $contact): void
    {
        $partner->syncRoles($roles);

        if ($contact !== null) {
            PartnerContact::query()->create($contact + [
                'company_id' => $partner->company_id,
                'partner_id' => $partner->id,
                'is_default' => true,
            ]);
        }
    }
}
