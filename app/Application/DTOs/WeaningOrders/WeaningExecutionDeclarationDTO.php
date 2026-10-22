<?php

declare(strict_types=1);

namespace App\Application\DTOs\WeaningOrders;

use App\Core\Enums\WeaningType;

/**
 * What the screen declares when an order's calves are weaned and it differs from the plan: where
 * each calf went and how it was weaned. Destinations travel with the screen's own key, as an
 * order's do when it is emitted.
 */
final readonly class WeaningExecutionDeclarationDTO
{
    /**
     * @param list<array{key: string, label: string, target_batch_id: ?int, new_batch_name: ?string, is_confined: ?bool, stays_in_place: bool}> $destinations
     * @param array<int, string> $destinationKeyByCaravanId the screen key each calf went to
     */
    public function __construct(
        public array $destinations,
        public array $destinationKeyByCaravanId,
        public ?WeaningType $weaningType
    ) {
    }

    /**
     * Null when the request declares neither destinations nor a type: the plan stands.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $hasDestinations = isset($data['destinations']) && $data['destinations'] !== [];
        $type = isset($data['weaning_type']) ? WeaningType::tryFrom((string) $data['weaning_type']) : null;

        if (!$hasDestinations && $type === null) {
            return null;
        }

        $keyByCaravanId = [];
        foreach ((array) ($data['animals'] ?? []) as $animal) {
            if (!empty($animal['destination_key'])) {
                $keyByCaravanId[(int) $animal['caravan_id']] = (string) $animal['destination_key'];
            }
        }

        return new self(
            destinations: $hasDestinations ? EmitWeaningOrderDTO::fromArray(['destinations' => $data['destinations']], 0, null)->destinations : [],
            destinationKeyByCaravanId: $keyByCaravanId,
            weaningType: $type
        );
    }
}
