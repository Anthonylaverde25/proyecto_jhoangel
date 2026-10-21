<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Interfaces\ICaravanDeathRepository;
use App\Models\CaravanDeath;
use App\Models\ServiceOrderMale;

final class EloquentCaravanDeathRepository implements ICaravanDeathRepository
{
    public function save(
        int $companyId,
        int $caravanId,
        string $deathDate,
        int $deathCauseId,
        ?string $observations,
        ?int $fromBatchId,
        int $deathBatchId,
        ?int $movementId,
        ?int $userId
    ): int {
        return (int) CaravanDeath::create([
            'company_id' => $companyId,
            'caravan_id' => $caravanId,
            'death_date' => $deathDate,
            'death_cause_id' => $deathCauseId,
            'observations' => $observations,
            'from_batch_id' => $fromBatchId,
            'death_batch_id' => $deathBatchId,
            'caravan_movement_id' => $movementId,
            'registered_by_user_id' => $userId,
        ])->id;
    }

    public function deathDates(array $caravanIds): array
    {
        if ($caravanIds === []) {
            return [];
        }

        return CaravanDeath::withoutGlobalScopes()
            ->whereIn('caravan_id', $caravanIds)
            ->get(['caravan_id', 'death_date'])
            ->mapWithKeys(fn (CaravanDeath $death) => [(int) $death->caravan_id => $death->death_date->format('Y-m-d')])
            ->all();
    }

    public function activeServiceBulls(array $caravanIds): array
    {
        if ($caravanIds === []) {
            return [];
        }

        return ServiceOrderMale::withoutGlobalScopes()
            ->join('service_orders', 'service_orders.id', '=', 'service_order_males.service_order_id')
            ->whereIn('service_order_males.male_caravan_id', $caravanIds)
            ->where('service_order_males.status', 'ACTIVE')
            ->pluck('service_orders.code', 'service_order_males.male_caravan_id')
            ->mapWithKeys(fn ($code, $id) => [(int) $id => (string) $code])
            ->all();
    }
}
