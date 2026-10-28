<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\PartnerContactEntity;
use App\Core\Entities\PartnerEntity;
use App\Core\Enums\PartnerRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PartnerEntity $resource
 */
class PartnerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $partner = $this->resource;
        $default = $partner->defaultContact();

        return [
            'id'              => $partner->getId(),
            'name'            => $partner->getName(),
            'commercial_name' => $partner->getCommercialName(),
            'cuit'            => $partner->getCuit(),
            'cuit_locked'     => $partner->isCuitLocked(),
            'location'        => $partner->getLocation(),
            'is_active'       => $partner->isActive(),
            'roles'           => array_map(fn (PartnerRole $role) => $role->value, $partner->getRoles()),
            'is_supplier'     => $partner->isSupplier(),
            'is_customer'     => $partner->isCustomer(),
            'default_contact' => $default !== null ? (new PartnerContactResource($default))->resolve($request) : null,
            'contacts'        => array_map(
                fn (PartnerContactEntity $contact) => (new PartnerContactResource($contact))->resolve($request),
                $partner->getContacts()
            ),
            'farms'           => FarmResource::collection($partner->getFarms()),
            'created_at'      => $partner->getCreatedAt()?->format('Y-m-d H:i:s'),

            // Transitional shape of /providers (03 §2): derived, removed with the alias.
            'supplies_livestock' => $partner->hasRole(PartnerRole::SUPPLIER_LIVESTOCK),
            'supplies_items'     => $partner->hasRole(PartnerRole::SUPPLIER_ITEMS),
            'email'              => $default?->getEmail(),
            'phone'              => $default?->getPhone(),
        ];
    }
}
