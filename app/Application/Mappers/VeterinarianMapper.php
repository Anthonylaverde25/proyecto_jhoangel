<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\VeterinarianEntity;
use App\Models\Veterinarian;

final class VeterinarianMapper
{
    public static function toDomain(Veterinarian $model): VeterinarianEntity
    {
        return new VeterinarianEntity(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            name: (string) $model->name,
            licenseNumber: (string) $model->license_number,
            userId: $model->user_id !== null ? (int) $model->user_id : null,
            cuit: $model->cuit,
            billingCuit: $model->billing_cuit,
            accreditationCode: $model->accreditation_code,
            phone: $model->phone,
            email: $model->email,
            isActive: (bool) $model->is_active
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function toPersistence(VeterinarianEntity $entity): array
    {
        return [
            'company_id' => $entity->getCompanyId(),
            'user_id' => $entity->getUserId(),
            'cuit' => $entity->getCuit(),
            'billing_cuit' => $entity->getBillingCuit(),
            'name' => $entity->getName(),
            'license_number' => $entity->getLicenseNumber(),
            'accreditation_code' => $entity->getAccreditationCode(),
            'phone' => $entity->getPhone(),
            'email' => $entity->getEmail(),
            'is_active' => $entity->isActive(),
        ];
    }
}
