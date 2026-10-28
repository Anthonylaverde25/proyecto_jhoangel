<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Entities\PartnerEntity;
use App\Core\Enums\PartnerRole;
use App\Core\Exceptions\ExitOrderDomainException;
use App\Core\Interfaces\IPartnerRepository;

/**
 * E6: a sale needs a buyer, and it is an active partner of the company that buys livestock.
 */
final class ExitBuyerGuard
{
    public function __construct(private readonly IPartnerRepository $partners)
    {
    }

    /** @throws ExitOrderDomainException on buyer_partner_id */
    public function require(int $partnerId, int $companyId): PartnerEntity
    {
        $partner = $this->partners->findById($partnerId);

        if ($partner === null || $partner->getCompanyId() !== $companyId) {
            throw ExitOrderDomainException::buyerInvalid('El comprador no existe en esta empresa.');
        }
        if (!$partner->isActive()) {
            throw ExitOrderDomainException::buyerInvalid("{$partner->getName()} está inactivo.");
        }
        if (!$partner->hasRole(PartnerRole::CUSTOMER_LIVESTOCK)) {
            throw ExitOrderDomainException::buyerInvalid("{$partner->getName()} no está marcado como cliente que compra hacienda.");
        }

        return $partner;
    }
}
