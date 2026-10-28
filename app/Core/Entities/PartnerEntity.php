<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\PartnerRole;
use App\Core\Exceptions\PartnerException;

/**
 * A third party the company deals with: it may sell to us, buy from us, or both (egreso plan v5,
 * 02). What it does are its roles (C1: at least one); who we talk to are its contacts, one of them
 * the default (C7–C9).
 */
final class PartnerEntity
{
    /** @var list<PartnerRole> */
    private array $roles;

    /**
     * @param list<PartnerRole> $roles
     * @param FarmEntity[] $farms
     * @param list<PartnerContactEntity> $contacts
     */
    public function __construct(
        private readonly ?int $id,
        private string $name,
        private ?string $commercialName,
        private string $cuit,
        private ?string $location,
        array $roles,
        private bool $isActive = true,
        private ?\DateTimeInterface $createdAt = null,
        private array $farms = [],
        private ?int $companyId = null,
        private bool $hasFiscalDocuments = false,
        private array $contacts = [],
    ) {
        $this->roles = self::assertHasRole($roles);
    }

    /**
     * @param list<PartnerRole> $roles
     * @return list<PartnerRole> without repetitions, in the enum's order
     */
    private static function assertHasRole(array $roles): array
    {
        $unique = array_values(array_filter(
            PartnerRole::cases(),
            fn (PartnerRole $role) => in_array($role, $roles, true)
        ));
        if ($unique === []) {
            throw PartnerException::withoutRole();
        }

        return $unique;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompanyId(): ?int
    {
        return $this->companyId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCommercialName(): ?string
    {
        return $this->commercialName;
    }

    public function getCuit(): string
    {
        return $this->cuit;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    /** @return FarmEntity[] */
    public function getFarms(): array
    {
        return $this->farms;
    }

    /** @return list<PartnerRole> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function hasRole(PartnerRole $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function isSupplier(): bool
    {
        return array_filter($this->roles, fn (PartnerRole $role) => $role->isSupplier()) !== [];
    }

    public function isCustomer(): bool
    {
        return array_filter($this->roles, fn (PartnerRole $role) => $role->isCustomer()) !== [];
    }

    /** @param list<PartnerRole> $roles */
    public function changeRoles(array $roles): void
    {
        $this->roles = self::assertHasRole($roles);
    }

    /** Once it issued a fiscal document, the CUIT is what that document says: it no longer changes. */
    public function isCuitLocked(): bool
    {
        return $this->hasFiscalDocuments;
    }

    /** Expects the digits of an already validated CUIT (`Cuit::requireValid`). */
    public function changeCuit(string $digits): void
    {
        if ($this->hasFiscalDocuments) {
            throw PartnerException::cuitLocked();
        }

        $this->cuit = $digits;
    }

    public function updateDetails(string $name, ?string $commercialName, ?string $location): void
    {
        $this->name = $name;
        $this->commercialName = $commercialName;
        $this->location = $location;
    }

    public function activate(): void
    {
        $this->isActive = true;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }

    /** @return list<PartnerContactEntity> the default one first */
    public function getContacts(): array
    {
        $contacts = $this->contacts;
        usort($contacts, fn (PartnerContactEntity $a, PartnerContactEntity $b) => [$b->isDefault(), $a->getId() ?? PHP_INT_MAX]
            <=> [$a->isDefault(), $b->getId() ?? PHP_INT_MAX]);

        return $contacts;
    }

    public function defaultContact(): ?PartnerContactEntity
    {
        foreach ($this->contacts as $contact) {
            if ($contact->isDefault()) {
                return $contact;
            }
        }

        return null;
    }

    public function findContact(int $contactId): ?PartnerContactEntity
    {
        foreach ($this->contacts as $contact) {
            if ($contact->getId() === $contactId) {
                return $contact;
            }
        }

        return null;
    }

    /** C7: the first contact becomes the default without asking. */
    public function addContact(PartnerContactEntity $contact): PartnerContactEntity
    {
        if ($this->contacts === []) {
            $contact->markDefault();
        } else {
            $contact->unmarkDefault();
        }
        $this->contacts[] = $contact;

        return $contact;
    }

    /** C8: the previous default loses the mark. */
    public function setDefaultContact(int $contactId): void
    {
        $target = $this->requireContact($contactId);
        foreach ($this->contacts as $contact) {
            $contact->unmarkDefault();
        }
        $target->markDefault();
    }

    /** C9: the default goes only when it is the last one; otherwise another is chosen first. */
    public function removeContact(int $contactId): void
    {
        $target = $this->requireContact($contactId);
        if ($target->isDefault() && count($this->contacts) > 1) {
            throw PartnerException::defaultContactInUse();
        }

        $this->contacts = array_values(array_filter(
            $this->contacts,
            fn (PartnerContactEntity $contact) => $contact !== $target
        ));
    }

    private function requireContact(int $contactId): PartnerContactEntity
    {
        return $this->findContact($contactId)
            ?? throw new \App\Core\Exceptions\DomainException("La persona de contacto {$contactId} no es de este tercero.");
    }
}
