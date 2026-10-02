<?php

declare(strict_types=1);

namespace App\Application\DTOs\EntryOrders;

use App\Core\Entities\EntryOrderBreedEntity;
use App\Core\Enums\BatchNameMode;
use App\Core\Enums\SexComposition;
use App\Core\Enums\TroopCondition;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\ValueObjects\EntryTroop;

/**
 * What the "Alta de Lote Externo" form sends: the troop, the name of its batch and whether the
 * purchase is confirmed now or kept as a draft.
 */
final readonly class StoreEntryOrderDTO
{
    /**
     * @param array<string, mixed> $troop validated troop fields
     */
    public function __construct(
        public int $companyId,
        public ?int $userId,
        public array $troop,
        public ?string $batchName,
        public BatchNameMode $batchNameMode,
        public bool $confirm
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, int $companyId, ?int $userId): self
    {
        return new self(
            companyId: $companyId,
            userId: $userId,
            troop: $data,
            batchName: isset($data['batch_name']) ? trim((string) $data['batch_name']) : null,
            batchNameMode: BatchNameMode::tryFrom((string) ($data['batch_name_mode'] ?? '')) ?? BatchNameMode::CUSTOM,
            confirm: (bool) ($data['confirm'] ?? false)
        );
    }

    /**
     * @throws EntryOrderDomainException
     */
    public function toTroop(): EntryTroop
    {
        $t = $this->troop;
        $sex = SexComposition::from((string) $t['sex_composition']);
        $breeds = [];

        foreach (array_values($t['breeds'] ?? []) as $index => $line) {
            $breeds[] = new EntryOrderBreedEntity(
                id: null,
                position: $index + 1,
                breedId: (int) $line['breed_id'],
                colorId: isset($line['color_id']) ? (int) $line['color_id'] : null
            );
        }

        $auction = isset($t['auction_number']) ? trim((string) $t['auction_number']) : '';

        return new EntryTroop(
            providerId: (int) $t['provider_id'],
            farmId: (int) $t['farm_id'],
            auctionNumber: $auction !== '' ? $auction : null,
            headCount: (int) $t['head_count'],
            categoryId: (int) $t['category_id'],
            sexComposition: $sex,
            maleCount: $sex === SexComposition::MIXED && isset($t['male_count']) ? (int) $t['male_count'] : null,
            femaleCount: $sex === SexComposition::MIXED && isset($t['female_count']) ? (int) $t['female_count'] : null,
            condition: TroopCondition::from((string) $t['condition']),
            ageMinMonths: isset($t['age_min_months']) ? (int) $t['age_min_months'] : null,
            ageMaxMonths: isset($t['age_max_months']) ? (int) $t['age_max_months'] : null,
            knowsToEat: (bool) $t['knows_to_eat'],
            tickVaccinated: (bool) $t['tick_vaccinated'],
            shrinkPercent: isset($t['shrink_percent']) ? (float) $t['shrink_percent'] : null,
            estimatedWeight: (float) $t['estimated_weight'],
            minWeight: isset($t['min_weight']) ? (float) $t['min_weight'] : null,
            maxWeight: isset($t['max_weight']) ? (float) $t['max_weight'] : null,
            purchaseDate: (string) $t['purchase_date'],
            responsable: self::text($t['responsable'] ?? null),
            observations: self::text($t['observations'] ?? null),
            breeds: $breeds
        );
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
