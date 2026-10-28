<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * What a partner does with the company (egreso plan v5, 02 §2). A new role is a new case, never a
 * migration: roles are rows of partner_roles.
 */
enum PartnerRole: string
{
    case SUPPLIER_LIVESTOCK = 'SUPPLIER_LIVESTOCK';
    case SUPPLIER_ITEMS = 'SUPPLIER_ITEMS';
    case CUSTOMER_LIVESTOCK = 'CUSTOMER_LIVESTOCK';
    case CUSTOMER_ITEMS = 'CUSTOMER_ITEMS';

    public function label(): string
    {
        return match ($this) {
            self::SUPPLIER_LIVESTOCK => 'Vende hacienda',
            self::SUPPLIER_ITEMS => 'Vende artículos',
            self::CUSTOMER_LIVESTOCK => 'Compra hacienda',
            self::CUSTOMER_ITEMS => 'Compra artículos',
        };
    }

    public function isSupplier(): bool
    {
        return $this === self::SUPPLIER_LIVESTOCK || $this === self::SUPPLIER_ITEMS;
    }

    public function isCustomer(): bool
    {
        return !$this->isSupplier();
    }

    /** @return list<self> */
    public static function supplierRoles(): array
    {
        return array_values(array_filter(self::cases(), fn (self $role) => $role->isSupplier()));
    }

    /** @return list<self> */
    public static function customerRoles(): array
    {
        return array_values(array_filter(self::cases(), fn (self $role) => $role->isCustomer()));
    }
}
