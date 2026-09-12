<?php

declare(strict_types=1);

namespace App\Infrastructure\Veterinary;

use App\Core\Enums\VeterinaryPortalAccessMode;
use App\Core\Interfaces\IVeterinaryPortalContext;
use RuntimeException;

/**
 * Request-scoped identity of whoever is operating the veterinary portal. Populated by
 * `ResolveVeterinaryPortalAccess`, which accepts either an authenticated user holding the
 * `veterinarian` role or a temporary access token handed to an external professional.
 */
final class VeterinaryPortalContext implements IVeterinaryPortalContext
{
    private ?VeterinaryPortalAccessMode $accessMode = null;
    private ?int $companyId = null;
    private ?int $veterinarianId = null;
    private string $veterinarianName = '';
    private string $licenseNumber = '';
    private ?int $userId = null;
    private ?int $accessTokenId = null;
    private bool $readOnly = false;

    /** @var list<int> */
    private array $allowedBatchIds = [];

    /** @var list<int> */
    private array $allowedProtocolIds = [];

    /**
     * @param list<int> $allowedBatchIds
     * @param list<int> $allowedProtocolIds
     */
    public function resolve(
        VeterinaryPortalAccessMode $accessMode,
        int $companyId,
        int $veterinarianId,
        string $veterinarianName,
        string $licenseNumber,
        array $allowedBatchIds,
        ?int $userId = null,
        ?int $accessTokenId = null,
        array $allowedProtocolIds = [],
        bool $readOnly = false
    ): void {
        $this->accessMode = $accessMode;
        $this->companyId = $companyId;
        $this->veterinarianId = $veterinarianId;
        $this->veterinarianName = $veterinarianName;
        $this->licenseNumber = $licenseNumber;
        $this->allowedBatchIds = array_values(array_unique($allowedBatchIds));
        $this->userId = $userId;
        $this->accessTokenId = $accessTokenId;
        $this->allowedProtocolIds = array_values(array_unique($allowedProtocolIds));
        $this->readOnly = $readOnly;
    }

    public function isResolved(): bool
    {
        return $this->accessMode !== null && $this->veterinarianId !== null;
    }

    public function getAccessMode(): ?VeterinaryPortalAccessMode
    {
        return $this->accessMode;
    }

    public function getCompanyId(): int
    {
        return $this->companyId ?? throw new RuntimeException('Veterinary portal context has not been resolved.');
    }

    public function getVeterinarianId(): int
    {
        return $this->veterinarianId ?? throw new RuntimeException('Veterinary portal context has not been resolved.');
    }

    public function getVeterinarianName(): string
    {
        return $this->veterinarianName;
    }

    public function getLicenseNumber(): string
    {
        return $this->licenseNumber;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * @return list<int>
     */
    public function getAllowedBatchIds(): array
    {
        return $this->allowedBatchIds;
    }

    public function canAccessBatch(int $batchId): bool
    {
        return in_array($batchId, $this->allowedBatchIds, true);
    }

    /**
     * @return list<int>
     */
    public function getAllowedProtocolIds(): array
    {
        return $this->allowedProtocolIds;
    }

    /**
     * An unrestricted session reaches any act; ownership is still enforced by the use cases,
     * which compare the act's professional against this session's.
     */
    public function canAccessAct(int $actId): bool
    {
        return $this->allowedProtocolIds === [] || in_array($actId, $this->allowedProtocolIds, true);
    }




    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    public function getAccessTokenId(): ?int
    {
        return $this->accessTokenId;
    }
}
