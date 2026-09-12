<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\UserInvitationEntity;
use App\Core\Interfaces\IUserInvitationRepository;
use App\Models\UserInvitation;
use DateTimeImmutable;
use Illuminate\Support\Carbon;

class EloquentUserInvitationRepository implements IUserInvitationRepository
{
    public function save(UserInvitationEntity $invitation, string $tokenHash, ?int $invitedByUserId): UserInvitationEntity
    {
        $model = UserInvitation::create([
            'company_id' => $invitation->getCompanyId(),
            'veterinarian_id' => $invitation->getVeterinarianId(),
            'email' => $invitation->getEmail(),
            'token_hash' => $tokenHash,
            'expires_at' => $invitation->getExpiresAt()->format('Y-m-d H:i:s'),
            'invited_by_user_id' => $invitedByUserId,
        ]);

        return $this->toDomain($model->fresh(['veterinarian']), $invitation->getPlainToken());
    }

    public function findByPlainToken(string $plainToken): ?UserInvitationEntity
    {
        $model = UserInvitation::withoutGlobalScopes()
            ->with('veterinarian')
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        return $model ? $this->toDomain($model) : null;
    }

    public function markAccepted(int $invitationId, int $userId): void
    {
        UserInvitation::withoutGlobalScopes()
            ->where('id', $invitationId)
            ->update(['accepted_at' => Carbon::now(), 'accepted_user_id' => $userId]);
    }

    public function revokePending(int $veterinarianId, int $companyId): void
    {
        // Expiring rather than deleting: an invitation that was sent is a fact, and the reason a
        // link stopped working is worth being able to answer.
        UserInvitation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('veterinarian_id', $veterinarianId)
            ->whereNull('accepted_at')
            ->update(['expires_at' => Carbon::now()->subSecond()]);
    }

    private function toDomain(UserInvitation $model, ?string $plainToken = null): UserInvitationEntity
    {
        return new UserInvitationEntity(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            veterinarianId: (int) $model->veterinarian_id,
            email: (string) $model->email,
            expiresAt: new DateTimeImmutable((string) $model->expires_at),
            acceptedAt: $model->accepted_at ? new DateTimeImmutable((string) $model->accepted_at) : null,
            veterinarianName: $model->relationLoaded('veterinarian') ? $model->veterinarian?->name : null,
            licenseNumber: $model->relationLoaded('veterinarian') ? $model->veterinarian?->license_number : null,
            plainToken: $plainToken
        );
    }
}
