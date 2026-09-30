<?php

declare(strict_types=1);

namespace App\Application\DTOs\TransferOrders;

use App\Core\Enums\TransferOrderCategoryMode;

/**
 * What the transfer screen decided, on its way to becoming an order.
 *
 * Destinations travel with the screen's own key, and animals point at them by it. The order
 * keys them again by their normalised label, which is what the paper will carry.
 */
final readonly class EmitTransferOrderDTO
{
    /**
     * @param list<array{key: string, label: string, target_batch_id: ?int, new_batch_name: ?string, new_batch_type_id: ?int, is_confined: ?bool}> $destinations
     * @param list<array{caravan_id: int, destination_key: ?string, target_category_id?: ?int, target_subcategory_id?: ?int}> $animals
     */
    public function __construct(
        public int $companyId,
        public ?int $requestedByUserId,
        public int $sourceBatchId,
        public int $destinationActivityId,
        public string $destinationMode,
        public string $movementDate,
        public ?string $responsable,
        public ?string $observations,
        public array $destinations,
        public array $animals,
        /** False (the default) creates a draft; true creates the order already issued. */
        public bool $issue = false,
        public TransferOrderCategoryMode $categoryMode = TransferOrderCategoryMode::KEEP
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, int $companyId, ?int $userId): self
    {
        $destinations = [];
        foreach ((array) ($data['destinations'] ?? []) as $destination) {
            $destinations[] = [
                'key' => (string) ($destination['key'] ?? ''),
                'label' => trim((string) ($destination['label'] ?? '')),
                'target_batch_id' => isset($destination['target_batch_id']) ? (int) $destination['target_batch_id'] : null,
                'new_batch_name' => isset($destination['new_batch_name']) && trim((string) $destination['new_batch_name']) !== ''
                    ? trim((string) $destination['new_batch_name'])
                    : null,
                'new_batch_type_id' => isset($destination['new_batch_type_id']) ? (int) $destination['new_batch_type_id'] : null,
                'is_confined' => array_key_exists('is_confined', $destination) && $destination['is_confined'] !== null
                    ? (bool) $destination['is_confined']
                    : null,
            ];
        }

        $animals = [];
        foreach ((array) ($data['animals'] ?? []) as $animal) {
            $key = $animal['destination_key'] ?? null;

            $animals[] = [
                'caravan_id' => (int) ($animal['caravan_id'] ?? 0),
                'destination_key' => $key !== null && $key !== '' ? (string) $key : null,
                'target_category_id' => isset($animal['target_category_id']) ? (int) $animal['target_category_id'] : null,
                'target_subcategory_id' => isset($animal['target_subcategory_id']) ? (int) $animal['target_subcategory_id'] : null,
            ];
        }

        $responsable = trim((string) ($data['responsable'] ?? ''));
        $observations = trim((string) ($data['observations'] ?? ''));

        return new self(
            companyId: $companyId,
            requestedByUserId: $userId,
            sourceBatchId: (int) ($data['source_batch_id'] ?? 0),
            destinationActivityId: (int) ($data['destination_activity_id'] ?? 0),
            destinationMode: (string) ($data['destination_mode'] ?? ''),
            movementDate: (string) ($data['movement_date'] ?? now()->toDateString()),
            responsable: $responsable !== '' ? $responsable : null,
            observations: $observations !== '' ? $observations : null,
            destinations: $destinations,
            animals: $animals,
            issue: filter_var($data['issue'] ?? false, FILTER_VALIDATE_BOOLEAN),
            categoryMode: TransferOrderCategoryMode::tryFrom((string) ($data['category_mode'] ?? '')) ?? TransferOrderCategoryMode::KEEP
        );
    }
}
