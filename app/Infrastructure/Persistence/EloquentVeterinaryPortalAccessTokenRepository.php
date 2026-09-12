<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\VeterinaryPortalAccessTokenMapper;
use App\Core\Entities\VeterinaryPortalAccessTokenEntity;
use App\Core\Interfaces\IVeterinaryPortalAccessTokenRepository;
use App\Models\BullLabSample;
use App\Models\Caravan;
use App\Models\VeterinarianBatchAssignment;
use App\Models\VeterinaryPortalAccessToken;
use Illuminate\Support\Carbon;

class EloquentVeterinaryPortalAccessTokenRepository implements IVeterinaryPortalAccessTokenRepository
{
    /**
     * @return array<VeterinaryPortalAccessTokenEntity>
     */
    public function findAll(int $companyId, bool $activeOnly = false): array
    {
        $query = VeterinaryPortalAccessToken::query()
            ->with(['veterinarian', 'batch', 'diagnosticProtocol'])
            ->where('company_id', $companyId);

        if ($activeOnly) {
            $query->whereNull('revoked_at')->where('expires_at', '>', Carbon::now());
        }

        return $query->orderByDesc('id')
            ->get()
            ->map(fn (VeterinaryPortalAccessToken $model): VeterinaryPortalAccessTokenEntity => VeterinaryPortalAccessTokenMapper::toDomain(
                $model,
                $this->resolveAllowedBatchIds($model),
                null,
                $this->resolveAllowedProtocolIds($model)
            ))
            ->all();
    }

    /**
     * Resolved without a tenant company scope on purpose: the public portal request carries
     * no `X-Company-ID`; the token itself is what establishes the company.
     */
    public function findUsableByPlainToken(string $plainToken): ?VeterinaryPortalAccessTokenEntity
    {
        $model = VeterinaryPortalAccessToken::withoutGlobalScopes()
            ->with(['veterinarian', 'batch', 'diagnosticProtocol'])
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if ($model === null) {
            return null;
        }

        $entity = VeterinaryPortalAccessTokenMapper::toDomain(
            $model,
            $this->resolveAllowedBatchIds($model),
            null,
            $this->resolveAllowedProtocolIds($model)
        );

        // Unknown, revoked, expired and exhausted are deliberately indistinguishable to the caller.
        return $entity->isUsable() ? $entity : null;
    }

    public function findById(int $tokenId, int $companyId): ?VeterinaryPortalAccessTokenEntity
    {
        $model = VeterinaryPortalAccessToken::query()
            ->with(['veterinarian', 'batch', 'diagnosticProtocol'])
            ->where('id', $tokenId)
            ->where('company_id', $companyId)
            ->first();

        if ($model === null) {
            return null;
        }

        return VeterinaryPortalAccessTokenMapper::toDomain(
            $model,
            $this->resolveAllowedBatchIds($model),
            null,
            $this->resolveAllowedProtocolIds($model)
        );
    }

    public function save(VeterinaryPortalAccessTokenEntity $token): VeterinaryPortalAccessTokenEntity
    {
        $attributes = [
            'company_id' => $token->getCompanyId(),
            'veterinarian_id' => $token->getVeterinarianId(),
            'batch_id' => $token->getBatchId(),
            'diagnostic_protocol_id' => $token->getDiagnosticProtocolId(),
            'token_hash' => $token->getTokenHash(),
            'token_prefix' => $token->getTokenPrefix(),
            'label' => $token->getLabel(),
            'expires_at' => $token->getExpiresAt()->format('Y-m-d H:i:s'),
            'max_uses' => $token->getMaxUses(),
            'created_by_user_id' => $token->getCreatedByUserId(),
        ];

        if ($token->getId() !== null) {
            $model = VeterinaryPortalAccessToken::query()->findOrFail($token->getId());
            $model->update($attributes);
        } else {
            $model = VeterinaryPortalAccessToken::create($attributes);
        }

        $model = $model->fresh(['veterinarian', 'batch', 'diagnosticProtocol']);

        // The plaintext travels back exactly once, so the producer can copy the link.
        return VeterinaryPortalAccessTokenMapper::toDomain(
            $model,
            $this->resolveAllowedBatchIds($model),
            $token->getPlainToken(),
            $this->resolveAllowedProtocolIds($model)
        );
    }

    public function registerUsage(int $tokenId, ?string $ipAddress): void
    {
        VeterinaryPortalAccessToken::withoutGlobalScopes()
            ->where('id', $tokenId)
            ->update([
                'used_count' => \Illuminate\Support\Facades\DB::raw('used_count + 1'),
                'last_used_at' => Carbon::now(),
                'last_used_ip' => $ipAddress,
            ]);
    }

    public function revoke(int $tokenId, int $companyId, ?int $revokedByUserId, ?string $reason): bool
    {
        return VeterinaryPortalAccessToken::query()
            ->where('id', $tokenId)
            ->where('company_id', $companyId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => Carbon::now(),
                'revoked_by_user_id' => $revokedByUserId,
                'revoke_reason' => $reason,
            ]) > 0;
    }

    /**
     * ADR-16: precedence is narrowest first. A grant scoped to one extraction act sees only the
     * batches that act actually touched; then a batch scoped grant; and only failing both does
     * it inherit every batch currently assigned to the professional.
     *
     * @return list<int>
     */
    private function resolveAllowedBatchIds(VeterinaryPortalAccessToken $model): array
    {
        if ($model->diagnostic_protocol_id !== null) {
            return $this->batchIdsOfAct((int) $model->diagnostic_protocol_id, (int) $model->company_id);
        }

        if ($model->batch_id !== null) {
            return [(int) $model->batch_id];
        }

        return VeterinarianBatchAssignment::withoutGlobalScopes()
            ->where('company_id', $model->company_id)
            ->where('veterinarian_id', $model->veterinarian_id)
            ->whereNull('unassigned_at')
            ->pluck('batch_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function resolveAllowedProtocolIds(VeterinaryPortalAccessToken $model): array
    {
        return $model->diagnostic_protocol_id !== null ? [(int) $model->diagnostic_protocol_id] : [];
    }

    /**
     * Walked back from the tubes the act created: those bulls' batches, and nothing else.
     *
     * @return list<int>
     */
    private function batchIdsOfAct(int $actId, int $companyId): array
    {
        $caravanIds = BullLabSample::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('extraction_act_id', $actId)
            ->pluck('caravan_id')
            ->unique()
            ->all();

        if ($caravanIds === []) {
            return [];
        }

        return Caravan::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', $caravanIds)
            ->whereNotNull('batch_id')
            ->pluck('batch_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
