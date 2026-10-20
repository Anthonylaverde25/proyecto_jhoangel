<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Exceptions\ProviderException;

final class ProviderEntity
{
    public function __construct(
        private readonly ?int $id,
        private string $name,
        private ?string $commercialName,
        private string $cuit,
        private ?string $location,
        private ?string $email,
        private ?string $phone,
        private bool $isActive = true,
        private ?\DateTimeInterface $createdAt = null,
        /** @var FarmEntity[] */
        private array $farms = [],
        private ?int $companyId = null,
        private bool $suppliesLivestock = true,
        private bool $suppliesItems = false,
        private bool $hasFiscalDocuments = false
    ) {
        self::assertHasRole($suppliesLivestock, $suppliesItems);
    }

    /**
     * A provider sells livestock, items (articles or services), or both; never neither.
     */
    private static function assertHasRole(bool $suppliesLivestock, bool $suppliesItems): void
    {
        if (!$suppliesLivestock && !$suppliesItems) {
            throw new \App\Core\Exceptions\DomainException('El proveedor tiene que vender hacienda, artículos o servicios (al menos una de las dos).');
        }
    }

    public function getCompanyId(): ?int
    {
        return $this->companyId;
    }

    public function suppliesLivestock(): bool
    {
        return $this->suppliesLivestock;
    }

    public function suppliesItems(): bool
    {
        return $this->suppliesItems;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    /** Once it issued a fiscal document, the CUIT is what that document says: it no longer changes. */
    public function isCuitLocked(): bool
    {
        return $this->hasFiscalDocuments;
    }

    /** Expects the digits of an already validated CUIT (`Cuit::requireValid`). */
    public function changeCuit(string $digits): void
    {
        if ($this->hasFiscalDocuments) {
            throw ProviderException::cuitLocked();
        }

        $this->cuit = $digits;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    /**
     * @return FarmEntity[]
     */
    public function getFarms(): array
    {
        return $this->farms;
    }

    public function updateDetails(
        string $name,
        ?string $commercialName,
        ?string $location,
        ?string $email,
        ?string $phone
    ): void {
        $this->name = $name;
        $this->commercialName = $commercialName;
        $this->location = $location;
        $this->email = $email;
        $this->phone = $phone;
    }

    public function changeRoles(bool $suppliesLivestock, bool $suppliesItems): void
    {
        self::assertHasRole($suppliesLivestock, $suppliesItems);
        $this->suppliesLivestock = $suppliesLivestock;
        $this->suppliesItems = $suppliesItems;
    }

    public function activate(): void
    {
        $this->isActive = true;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }
}
