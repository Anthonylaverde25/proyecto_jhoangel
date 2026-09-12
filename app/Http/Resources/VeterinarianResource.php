<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\VeterinarianEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VeterinarianEntity
 */
class VeterinarianResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var VeterinarianEntity $this */
        return [
            'id' => $this->getId(),
            'company_id' => $this->getCompanyId(),
            'name' => $this->getName(),
            'license_number' => $this->getLicenseNumber(),
            'user_id' => $this->getUserId(),
            // ADR-38: identity of the person, and of the entity they invoice under.
            'cuit' => $this->getCuit(),
            'billing_cuit' => $this->getBillingCuit(),
            'accreditation_code' => $this->getAccreditationCode(),
            'phone' => $this->getPhone(),
            'email' => $this->getEmail(),
            'is_active' => $this->isActive(),
        ];
    }
}
