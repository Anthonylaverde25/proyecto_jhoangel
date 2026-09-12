<?php

declare(strict_types=1);

namespace App\Core\Entities;

use DateTimeImmutable;

/**
 * ADR-33: the professional's credential is born here, and the producer never sees it.
 */
final class UserInvitationEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $veterinarianId,
        private readonly string $email,
        private readonly DateTimeImmutable $expiresAt,
        private readonly ?DateTimeImmutable $acceptedAt = null,
        private readonly ?string $veterinarianName = null,
        private readonly ?string $licenseNumber = null,
        private readonly ?string $plainToken = null
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

    public function getVeterinarianId(): int
    {
        return $this->veterinarianId;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getAcceptedAt(): ?DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getVeterinarianName(): ?string
    {
        return $this->veterinarianName;
    }

    public function getLicenseNumber(): ?string
    {
        return $this->licenseNumber;
    }

    /** Returned only by the call that mints it; the store keeps the hash and nothing else. */
    public function getPlainToken(): ?string
    {
        return $this->plainToken;
    }

    public function isUsable(): bool
    {
        return $this->acceptedAt === null && $this->expiresAt > new DateTimeImmutable();
    }
}
