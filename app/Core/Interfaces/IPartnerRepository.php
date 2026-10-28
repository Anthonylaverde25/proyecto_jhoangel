<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\PartnerContactEntity;
use App\Core\Entities\PartnerEntity;
use App\Core\Enums\PartnerRole;

interface IPartnerRepository
{
    /**
     * Partners of the active company, with their contacts.
     *
     * @param list<PartnerRole>|null $roles only partners holding any of them; null = all
     * @return PartnerEntity[]
     */
    public function findAll(?array $roles = null): array;

    public function findById(int $id): ?PartnerEntity;

    public function findByCuit(string $cuit, int $companyId): ?PartnerEntity;

    /** Creates the partner with its roles, farms and contacts. */
    public function save(PartnerEntity $partner): PartnerEntity;

    /** Persists only the roles; farms and contacts are left untouched. */
    public function updateRoles(PartnerEntity $partner): void;

    /** Persists only the identity fields; roles, farms and contacts are left untouched. */
    public function updateDetails(PartnerEntity $partner): void;

    /** Inserts or updates one contact of the partner; returns it with its id. */
    public function saveContact(PartnerEntity $partner, PartnerContactEntity $contact): PartnerContactEntity;

    /** Moves the default mark to that contact, in one transaction (C8). */
    public function setDefaultContact(PartnerEntity $partner, int $contactId): void;

    public function deleteContact(PartnerEntity $partner, int $contactId): void;
}
