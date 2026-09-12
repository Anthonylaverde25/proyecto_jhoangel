<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\VeterinaryPortalAccessTokenEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VeterinaryPortalAccessTokenEntity
 */
class VeterinaryPortalAccessTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var VeterinaryPortalAccessTokenEntity $this */
        return [
            'id' => $this->getId(),
            'veterinarian_id' => $this->getVeterinarianId(),
            'veterinarian_name' => $this->getVeterinarianName(),
            'license_number' => $this->getLicenseNumber(),
            'batch_id' => $this->getBatchId(),

            // ADR-16: the act this grant is narrowed to, if any.
            'diagnostic_protocol_id' => $this->getDiagnosticProtocolId(),
            'protocol_number' => $this->getProtocolNumber(),
            'is_scoped_to_act' => $this->isScopedToAct(),
            'batch_name' => $this->getBatchName(),
            'allowed_batch_ids' => $this->getAllowedBatchIds(),
            'label' => $this->getLabel(),
            'token_prefix' => $this->getTokenPrefix(),
            'expires_at' => $this->getExpiresAt()->format('Y-m-d H:i:s'),
            'max_uses' => $this->getMaxUses(),
            'used_count' => $this->getUsedCount(),
            'last_used_at' => $this->getLastUsedAt()?->format('Y-m-d H:i:s'),
            'revoked_at' => $this->getRevokedAt()?->format('Y-m-d H:i:s'),
            'is_usable' => $this->isUsable(),

            // Present exactly once, on the response that mints the grant. Afterwards only the
            // SHA-256 hash survives, so the link cannot be recovered from the system.
            'plain_token' => $this->getPlainToken(),
            'access_url' => $this->buildAccessUrl(),
        ];
    }

    private function buildAccessUrl(): ?string
    {
        /** @var VeterinaryPortalAccessTokenEntity $this */
        $plainToken = $this->getPlainToken();

        if ($plainToken === null) {
            return null;
        }

        $template = (string) config(
            'livestock.veterinary_portal.public_url_template',
            'http://localhost:3000/vet-portal/:token'
        );

        return str_replace(':token', $plainToken, $template);
    }
}
