<?php

declare(strict_types=1);

namespace App\Application\UseCases\Partners;

use App\Application\DTOs\PartnerContactDTO;
use App\Core\Entities\PartnerContactEntity;
use App\Core\Interfaces\IPartnerRepository;

/** A new contact of the partner; the first one becomes the default (C7). */
final class AddPartnerContactUseCase
{
    public function __construct(
        private readonly IPartnerRepository $partners,
        private readonly PartnerOfCompany $partnerOfCompany,
    ) {
    }

    public function __invoke(int $partnerId, PartnerContactDTO $dto, int $companyId): PartnerContactEntity
    {
        $partner = ($this->partnerOfCompany)($partnerId, $companyId);

        $contact = $partner->addContact(new PartnerContactEntity(
            id: null,
            name: $dto->name,
            position: $dto->position,
            phone: $dto->phone,
            email: $dto->email,
            notes: $dto->notes,
        ));

        return $this->partners->saveContact($partner, $contact);
    }
}
