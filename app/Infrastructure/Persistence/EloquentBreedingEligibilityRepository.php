<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Enums\AnimalSex;
use App\Core\Interfaces\IBreedingEligibilityRepository;
use App\Core\ValueObjects\BreedingEligibility;
use App\Models\Caravan;
use App\Models\CaravanBreedingEligibilityChange;
use DateTimeImmutable;

final class EloquentBreedingEligibilityRepository implements IBreedingEligibilityRepository
{
    public function findAnimals(array $caravanIds, int $companyId): array
    {
        if ($caravanIds === []) {
            return [];
        }

        $animals = [];
        Caravan::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', $caravanIds)
            ->get(['id', 'identification', 'sex'])
            ->each(function (Caravan $caravan) use (&$animals): void {
                $sex = $caravan->sex instanceof AnimalSex ? $caravan->sex : AnimalSex::tryFrom((string) $caravan->sex);
                $animals[(int) $caravan->id] = [
                    'identification' => (string) $caravan->identification,
                    'sex' => $sex,
                ];
            });

        return $animals;
    }

    public function apply(
        array $caravanIds,
        int $companyId,
        BreedingEligibility $eligibility,
        ?string $note,
        ?int $userId,
        DateTimeImmutable $changedAt
    ): void {
        Caravan::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', $caravanIds)
            ->update([
                'is_breeding_eligible' => $eligibility->isEligible,
                'breeding_exclusion_reason' => $eligibility->reason?->value,
            ]);

        $rows = array_map(fn (int $caravanId) => [
            'company_id' => $companyId,
            'caravan_id' => $caravanId,
            'is_breeding_eligible' => $eligibility->isEligible,
            'reason' => $eligibility->reason?->value,
            'note' => $note,
            'changed_by_user_id' => $userId,
            'changed_at' => $changedAt->format('Y-m-d H:i:s'),
        ], $caravanIds);

        CaravanBreedingEligibilityChange::withoutGlobalScopes()->insert($rows);
    }
}
