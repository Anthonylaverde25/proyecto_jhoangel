<?php

declare(strict_types=1);

namespace App\Application\UseCases\Partners;

use App\Core\Interfaces\IPartnerRepository;

/** The default contact goes only when it is the last one (C9). */
final class RemovePartnerContactUseCase
{
    public function __construct(
        private readonly IPartnerRepository $partners,
        private readonly PartnerOfCompany $partnerOfCompany,
    ) {
    }

    public function __invoke(int $partnerId, int $contactId, int $companyId): void
    {
        $partner = ($this->partnerOfCompany)($partnerId, $companyId);
        PartnerContactOf::require($partner, $contactId);

        $partner->removeContact($contactId);
        $this->partners->deleteContact($partner, $contactId);
    }
}
