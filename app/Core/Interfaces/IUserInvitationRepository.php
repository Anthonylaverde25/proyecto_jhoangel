<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\UserInvitationEntity;

interface IUserInvitationRepository
{
    public function save(UserInvitationEntity $invitation, string $tokenHash, ?int $invitedByUserId): UserInvitationEntity;

    /** Resolved by hash: the plaintext never reaches the store. */
    public function findByPlainToken(string $plainToken): ?UserInvitationEntity;

    public function markAccepted(int $invitationId, int $userId): void;

    /** A professional has at most one live invitation; issuing a new one supersedes it. */
    public function revokePending(int $veterinarianId, int $companyId): void;
}
