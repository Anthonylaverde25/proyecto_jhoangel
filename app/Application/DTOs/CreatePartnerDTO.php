<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Core\Enums\PartnerRole;

final readonly class CreatePartnerDTO
{
    /**
     * @param list<PartnerRole> $roles
     * @param array<string, mixed>[] $farms
     */
    public function __construct(
        public string $name,
        public ?string $commercialName,
        public string $cuit,
        public ?string $location,
        public array $roles,
        public array $farms = [],
        public ?int $companyId = null,
        public ?PartnerContactDTO $contact = null,
    ) {
    }

    /**
     * Without roles the partner sells livestock, the default the provider form always had.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, ?int $companyId = null): self
    {
        $roles = array_map(fn ($role) => PartnerRole::from((string) $role), (array) ($data['roles'] ?? []));

        return new self(
            (string) ($data['name'] ?? ''),
            isset($data['commercial_name']) ? (string) $data['commercial_name'] : null,
            (string) ($data['cuit'] ?? ''),
            isset($data['location']) ? (string) $data['location'] : null,
            $roles !== [] ? array_values($roles) : [PartnerRole::SUPPLIER_LIVESTOCK],
            (array) ($data['farms'] ?? []),
            $companyId,
            isset($data['contact']) && is_array($data['contact']) ? PartnerContactDTO::fromArray($data['contact']) : null,
        );
    }
}
