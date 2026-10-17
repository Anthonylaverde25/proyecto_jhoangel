<?php

declare(strict_types=1);

namespace App\Application\DTOs\Caravans;

use App\Core\Enums\BreedingExclusionReason;

final class UpdateBreedingEligibilityDTO
{
    /**
     * @param int[] $caravanIds
     */
    public function __construct(
        public readonly array $caravanIds,
        public readonly bool $isEligible,
        public readonly ?BreedingExclusionReason $reason,
        public readonly ?string $note,
        public readonly int $companyId,
        public readonly ?int $userId
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, int $companyId, ?int $userId): self
    {
        $note = isset($data['note']) ? trim((string) $data['note']) : '';

        return new self(
            caravanIds: array_values(array_unique(array_map('intval', (array) ($data['caravan_ids'] ?? [])))),
            isEligible: (bool) $data['is_eligible'],
            reason: isset($data['reason']) ? BreedingExclusionReason::from((string) $data['reason']) : null,
            note: $note !== '' ? $note : null,
            companyId: $companyId,
            userId: $userId
        );
    }
}
