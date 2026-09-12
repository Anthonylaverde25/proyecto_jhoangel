<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class VeterinarianEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly string $name,
        private readonly string $licenseNumber,
        private readonly ?int $userId = null,
        // ADR-29 / ADR-38: identity of the PERSON. An institution stored here would be the
        // catalogue again, and it goes stale the day they change jobs.
        private readonly ?string $cuit = null,
        // The entity they invoice under, when it is not themselves (ADR-38).
        private readonly ?string $billingCuit = null,
        private readonly ?string $accreditationCode = null,
        private readonly ?string $phone = null,
        private readonly ?string $email = null,
        private readonly bool $isActive = true,

    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLicenseNumber(): string
    {
        return $this->licenseNumber;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getCuit(): ?string
    {
        return $this->cuit;
    }

    public function getAccreditationCode(): ?string
    {
        return $this->accreditationCode;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getBillingCuit(): ?string
    {
        return $this->billingCuit;
    }


    /**
     * ADR-8 + ADR-15: the signature snapshot frozen onto a protocol at signing time. Editing the
     * catalogue afterwards must not rewrite history, and that applies to the institution just as
     * much as to the professional: renaming a laboratory cannot retroactively move a historical
     * document to a different one.
     *
     * @return array{name: string, license_number: string, cuit: ?string, billing_cuit: ?string}
     */
    public function toSignatureSnapshot(): array
    {
        return [
            'name' => $this->name,
            'license_number' => $this->licenseNumber,
            'cuit' => $this->cuit,
            'billing_cuit' => $this->billingCuit,
        ];
    }
}
