<?php

declare(strict_types=1);

namespace App\Application\DTOs\BirthOrders;

/**
 * What the birth order screen decided, on its way to becoming an order: which pregnant females,
 * over which calving window, and who walks the rounds. There is no destination to declare — each
 * calf is born in its mother's batch.
 */
final readonly class EmitBirthOrderDTO
{
    /**
     * @param list<int> $motherIds
     */
    public function __construct(
        public int $companyId,
        public ?int $requestedByUserId,
        public ?string $periodStart,
        public ?string $periodEnd,
        public ?string $responsable,
        public ?string $observations,
        public array $motherIds,
        /** False (the default) creates a draft; true creates the order already issued. */
        public bool $issue = false
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, int $companyId, ?int $userId): self
    {
        $motherIds = [];
        foreach ((array) ($data['animals'] ?? []) as $animal) {
            $motherIds[] = (int) ($animal['caravan_id'] ?? 0);
        }

        return new self(
            companyId: $companyId,
            requestedByUserId: $userId,
            periodStart: self::date($data['period_start'] ?? null),
            periodEnd: self::date($data['period_end'] ?? null),
            responsable: self::text($data['responsable'] ?? null),
            observations: self::text($data['observations'] ?? null),
            motherIds: $motherIds,
            issue: filter_var($data['issue'] ?? false, FILTER_VALIDATE_BOOLEAN)
        );
    }

    private static function date(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? substr($value, 0, 10) : null;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
