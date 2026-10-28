<?php

declare(strict_types=1);

namespace App\Application\UseCases\Partners;

use App\Core\Entities\PartnerEntity;
use App\Core\Interfaces\IPartnerRepository;

/** Marks a contact as the default one; the previous default loses the mark (C8). */
final class SetDefaultPartnerContactUseCase
{
    public function __construct(
        private readonly IPartnerRepository $partners,
        private readonly PartnerOfCompany $partnerOfCompany,
    ) {
    }

    public function __invoke(int $partnerId, int $contactId, int $companyId): PartnerEntity
    {
        $partner = ($this->partnerOfCompany)($partnerId, $companyId);
        PartnerContactOf::require($partner, $contactId);

        $partner->setDefaultContact($contactId);
        $this->partners->setDefaultContact($partner, $contactId);

        return $this->partners->findById($partnerId);
    }
}
