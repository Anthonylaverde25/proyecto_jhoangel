<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\VeterinaryPortalAccessTokenEntity;

interface IVeterinaryPortalAccessTokenRepository
{
    /**
     * @return array<VeterinaryPortalAccessTokenEntity>
     */
    public function findAll(int $companyId, bool $activeOnly = false): array;

    /**
     * Resolve a plaintext token into its grant. Returns null when unknown, revoked,
     * expired or exhausted: the caller must not be able to tell those apart.
     */
    public function findUsableByPlainToken(string $plainToken): ?VeterinaryPortalAccessTokenEntity;

    public function save(VeterinaryPortalAccessTokenEntity $token): VeterinaryPortalAccessTokenEntity;

    public function registerUsage(int $tokenId, ?string $ipAddress): void;

    public function revoke(int $tokenId, int $companyId, ?int $revokedByUserId, ?string $reason): bool;

    /**
     * One grant by id. Never carries the plaintext token: only the hash survives minting.
     */
    public function findById(int $tokenId, int $companyId): ?VeterinaryPortalAccessTokenEntity;
}
