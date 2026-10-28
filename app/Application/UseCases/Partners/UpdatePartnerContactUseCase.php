<?php

declare(strict_types=1);

namespace App\Application\UseCases\Partners;

use App\Application\DTOs\PartnerContactDTO;
use App\Core\Entities\PartnerContactEntity;
use App\Core\Interfaces\IPartnerRepository;

/** Corrects a contact's details; the default mark has its own action (C8). */
final class UpdatePartnerContactUseCase
{
    public function __construct(
        private readonly IPartnerRepository $partners,
        private readonly PartnerOfCompany $partnerOfCompany,
    ) {
    }

    public function __invoke(int $partnerId, int $contactId, PartnerContactDTO $dto, int $companyId): PartnerContactEntity
    {
        $partner = ($this->partnerOfCompany)($partnerId, $companyId);
        $contact = PartnerContactOf::require($partner, $contactId);

        $contact->updateDetails($dto->name, $dto->position, $dto->phone, $dto->email, $dto->notes);

        return $this->partners->saveContact($partner, $contact);
    }
}
