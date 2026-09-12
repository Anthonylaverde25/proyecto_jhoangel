<?php

declare(strict_types=1);

namespace App\Core\Entities;

use DateTimeImmutable;

/**
 * A temporary grant to the veterinary portal. The plaintext secret is available only on the
 * request that mints it; afterwards only its SHA-256 hash and a display prefix survive.
 */
final class VeterinaryPortalAccessTokenEntity
{
    /**
     * @param list<int> $allowedBatchIds
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $veterinarianId,
        private readonly string $tokenHash,
        private readonly string $tokenPrefix,
        private readonly DateTimeImmutable $expiresAt,
        private readonly ?int $batchId = null,
        private readonly ?int $diagnosticProtocolId = null,
        private readonly ?string $label = null,
        private readonly ?int $maxUses = null,
        private readonly int $usedCount = 0,
        private readonly ?DateTimeImmutable $lastUsedAt = null,
        private readonly ?DateTimeImmutable $revokedAt = null,
        private readonly ?int $createdByUserId = null,
        private readonly ?string $veterinarianName = null,
        private readonly ?string $licenseNumber = null,
        private readonly ?string $healthCenterName = null,
        private readonly ?string $batchName = null,
        private readonly ?string $protocolNumber = null,
        private readonly array $allowedBatchIds = [],
        private readonly array $allowedProtocolIds = [],
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

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getTokenPrefix(): string
    {
        return $this->tokenPrefix;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getBatchId(): ?int
    {
        return $this->batchId;
    }

    public function getDiagnosticProtocolId(): ?int
    {
        return $this->diagnosticProtocolId;
    }

    public function getProtocolNumber(): ?string
    {
        return $this->protocolNumber;
    }

    /**
     * ADR-16: when non empty, the grant sees these extraction acts and nothing else.
     *
     * @return list<int>
     */
    public function getAllowedProtocolIds(): array
    {
        return $this->allowedProtocolIds;
    }

    public function isScopedToAct(): bool
    {
        return $this->diagnosticProtocolId !== null;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getMaxUses(): ?int
    {
        return $this->maxUses;
    }

    public function getUsedCount(): int
    {
        return $this->usedCount;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getCreatedByUserId(): ?int
    {
        return $this->createdByUserId;
    }

    public function getVeterinarianName(): ?string
    {
        return $this->veterinarianName;
    }

    public function getLicenseNumber(): ?string
    {
        return $this->licenseNumber;
    }


    public function getBatchName(): ?string
    {
        return $this->batchName;
    }

    /**
     * @return list<int>
     */
    public function getAllowedBatchIds(): array
    {
        return $this->allowedBatchIds;
    }

    /**
     * Only populated on the response that mints the token, so the producer can copy the link once.
     */
    public function getPlainToken(): ?string
    {
        return $this->plainToken;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new DateTimeImmutable());
    }

    public function isExhausted(): bool
    {
        return $this->maxUses !== null && $this->usedCount >= $this->maxUses;
    }

    public function isUsable(?DateTimeImmutable $now = null): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now) && !$this->isExhausted();
    }
}
