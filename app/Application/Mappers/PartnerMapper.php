<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\PartnerContactEntity;
use App\Core\Entities\PartnerEntity;
use App\Models\Partner;
use App\Models\PartnerContact;

class PartnerMapper
{
    public static function toEntity(Partner $model): PartnerEntity
    {
        $farms = [];
        if ($model->relationLoaded('farms')) {
            foreach ($model->farms as $farmModel) {
                $farms[] = FarmMapper::toEntity($farmModel);
            }
        }

        return new PartnerEntity(
            id: $model->id,
            name: $model->name,
            commercialName: $model->commercial_name,
            cuit: $model->cuit,
            location: $model->location,
            roles: $model->roleList(),
            isActive: (bool) $model->is_active,
            createdAt: $model->created_at,
            farms: $farms,
            companyId: $model->company_id !== null ? (int) $model->company_id : null,
            hasFiscalDocuments: (bool) ($model->fiscal_documents_exists ?? false),
            contacts: $model->contacts->map(fn (PartnerContact $contact) => self::contactToEntity($contact))->values()->all(),
        );
    }

    public static function contactToEntity(PartnerContact $model): PartnerContactEntity
    {
        return new PartnerContactEntity(
            id: $model->id,
            name: $model->name,
            position: $model->position,
            phone: $model->phone,
            email: $model->email,
            notes: $model->notes,
            isDefault: (bool) $model->is_default,
        );
    }

    public static function toModel(PartnerEntity $entity, ?Partner $model = null): Partner
    {
        $model ??= new Partner();

        $model->name = $entity->getName();
        $model->commercial_name = $entity->getCommercialName();
        $model->cuit = $entity->getCuit();
        $model->location = $entity->getLocation();
        $model->is_active = $entity->isActive();

        if ($entity->getCompanyId() !== null) {
            $model->company_id = $entity->getCompanyId();
        }

        return $model;
    }

    /** @return array<string, mixed> */
    public static function contactToAttributes(PartnerContactEntity $contact): array
    {
        return [
            'name' => $contact->getName(),
            'position' => $contact->getPosition(),
            'phone' => $contact->getPhone(),
            'email' => $contact->getEmail(),
            'notes' => $contact->getNotes(),
            'is_default' => $contact->isDefault(),
        ];
    }
}
