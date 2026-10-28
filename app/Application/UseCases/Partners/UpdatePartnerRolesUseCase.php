<?php

declare(strict_types=1);

namespace App\Application\UseCases\Partners;

use App\Core\Entities\PartnerEntity;
use App\Core\Enums\PartnerRole;
use App\Core\Exceptions\ItemCatalogException;
use App\Core\Exceptions\PartnerException;
use App\Core\Interfaces\IExitOrderRepository;
use App\Core\Interfaces\IItemRepository;
use App\Core\Interfaces\IPartnerRepository;

/**
 * What a partner does with the company. A role is not taken away while something depends on it
 * (C2): item links need "sells items", origin farms need "sells livestock", exit orders need
 * "buys livestock".
 */
final class UpdatePartnerRolesUseCase
{
    public function __construct(
        private readonly IPartnerRepository $partners,
        private readonly IItemRepository $items,
        private readonly IExitOrderRepository $exitOrders,
        private readonly PartnerOfCompany $partnerOfCompany,
    ) {
    }

    /** @param list<PartnerRole> $roles */
    public function __invoke(int $id, array $roles, int $companyId): PartnerEntity
    {
        $partner = ($this->partnerOfCompany)($id, $companyId);

        $removes = fn (PartnerRole $role) => $partner->hasRole($role) && !in_array($role, $roles, true);

        if ($removes(PartnerRole::SUPPLIER_ITEMS)) {
            $links = $this->items->countLinksOfProvider($id, $companyId);
            if ($links > 0) {
                throw ItemCatalogException::providerLinkedToItems($links);
            }
        }
        if ($removes(PartnerRole::SUPPLIER_LIVESTOCK) && count($partner->getFarms()) > 0) {
            throw ItemCatalogException::providerHasFarms(count($partner->getFarms()));
        }
        if ($removes(PartnerRole::CUSTOMER_LIVESTOCK)) {
            $exits = $this->exitOrders->countByBuyer($id, $companyId);
            if ($exits > 0) {
                throw PartnerException::customerHasExitOrders($exits);
            }
        }

        $partner->changeRoles($roles);
        $this->partners->updateRoles($partner);

        return $this->partners->findById($id);
    }
}
