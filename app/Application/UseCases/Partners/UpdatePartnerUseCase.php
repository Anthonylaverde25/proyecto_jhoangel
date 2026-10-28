<?php

declare(strict_types=1);

namespace App\Application\UseCases\Partners;

use App\Application\DTOs\UpdatePartnerDTO;
use App\Core\Entities\PartnerEntity;
use App\Core\Interfaces\IPartnerRepository;
use App\Core\ValueObjects\Cuit;

/**
 * Corrects a partner's identity. The CUIT is validated only when it changes, and it cannot change
 * once the partner issued a fiscal document (voided ones included).
 */
final class UpdatePartnerUseCase
{
    public function __construct(
        private readonly IPartnerRepository $partners,
        private readonly PartnerOfCompany $partnerOfCompany,
    ) {
    }

    public function __invoke(int $id, UpdatePartnerDTO $dto, int $companyId): PartnerEntity
    {
        $partner = ($this->partnerOfCompany)($id, $companyId);

        if (Cuit::fromString($dto->cuit)->value() !== $partner->getCuit()) {
            $partner->changeCuit(Cuit::requireValid($dto->cuit)->value());
        }

        $partner->updateDetails($dto->name, $dto->commercialName, $dto->location);
        $this->partners->updateDetails($partner);

        return $this->partners->findById($id);
    }
}
