<?php

declare(strict_types=1);

namespace App\Application\UseCases\Partners;

use App\Core\Entities\PartnerEntity;
use App\Core\Interfaces\IPartnerRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The partner the request names, only when it belongs to the active company: any other is a 404.
 */
final class PartnerOfCompany
{
    public function __construct(
        private readonly IPartnerRepository $partners,
    ) {
    }

    public function __invoke(int $id, int $companyId): PartnerEntity
    {
        $partner = $this->partners->findById($id);
        if ($partner === null || $partner->getCompanyId() !== $companyId) {
            throw new ModelNotFoundException("Partner {$id} not found.");
        }

        return $partner;
    }
}
