<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Enums\CastrationMethod;
use App\Core\Interfaces\ICaravanCastrationRepository;
use App\Core\ValueObjects\CastrationRecord;
use App\Models\Caravan;
use App\Models\CaravanCastration;
use App\Models\ServiceOrderMale;
use Illuminate\Database\Eloquent\Builder;

final class EloquentCaravanCastrationRepository implements ICaravanCastrationRepository
{
    public function activeByCaravans(array $caravanIds): array
    {
        if ($caravanIds === []) {
            return [];
        }

        return CaravanCastration::withoutGlobalScopes()
            ->whereIn('caravan_id', $caravanIds)
            ->whereNull('voided_at')
            ->with('fromCategory')
            ->get()
            ->mapWithKeys(function (CaravanCastration $row) {
                $record = new CastrationRecord(
                    id: (int) $row->id,
                    castratedOn: $row->castrated_on->format('Y-m-d'),
                    method: $row->method ? CastrationMethod::tryFrom($row->method) : null,
                    notes: $row->notes,
                    fromCategoryCode: $row->fromCategory?->code
                );

                return [(int) $row->caravan_id => $record];
            })
            ->all();
    }

    public function save(
        int $companyId,
        int $caravanId,
        string $castratedOn,
        ?CastrationMethod $method,
        ?string $notes,
        ?int $fromCategoryId,
        ?int $userId
    ): int {
        $castration = CaravanCastration::create([
            'company_id' => $companyId,
            'caravan_id' => $caravanId,
            'castrated_on' => $castratedOn,
            'method' => $method?->value,
            'notes' => $notes,
            'from_category_id' => $fromCategoryId,
            'registered_by_user_id' => $userId,
        ]);

        return (int) $castration->id;
    }

    public function findActive(int $castrationId): ?array
    {
        /** @var CaravanCastration|null $row */
        $row = CaravanCastration::where('id', $castrationId)
            ->whereNull('voided_at')
            ->with('fromCategory')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'company_id' => (int) $row->company_id,
            'caravan_id' => (int) $row->caravan_id,
            'castrated_on' => $row->castrated_on->format('Y-m-d'),
            'method' => $row->method ? CastrationMethod::tryFrom($row->method) : null,
            'notes' => $row->notes,
            'from_category_id' => $row->from_category_id ? (int) $row->from_category_id : null,
            'from_category_code' => $row->fromCategory?->code,
        ];
    }

    public function void(int $castrationId, ?string $reason, ?int $userId): void
    {
        CaravanCastration::where('id', $castrationId)->update([
            'voided_at' => now(),
            'void_reason' => $reason,
            'voided_by_user_id' => $userId,
        ]);
    }

    private function candidateQuery(int $companyId, int $batchId): Builder
    {
        return Caravan::withoutGlobalScopes()
            ->where('caravans.company_id', $companyId)
            ->where('caravans.batch_id', $batchId)
            ->where('caravans.sex', 'M')
            ->whereDoesntHave('death')
            ->whereDoesntHave('exitLine')
            ->whereHas('category', function (Builder $q) {
                $q->whereIn('code', ['TERNERO', 'TORITO']);
            })
            // A ternero must not have an active castration. Toritos don't have active castrations
            ->whereDoesntHave('castrations', function (Builder $q) {
                $q->whereNull('voided_at');
            });
    }

    public function candidatesByBatch(int $companyId, int $batchId): array
    {
        $caravans = $this->candidateQuery($companyId, $batchId)
            ->with(['category', 'lineage'])
            ->get();

        if ($caravans->isEmpty()) {
            return [];
        }

        $caravanIds = $caravans->pluck('id')->map(fn ($id) => (int) $id)->all();
        $activeServiceBulls = $this->activeServiceBulls($caravanIds);

        return $caravans->map(function (Caravan $c) use ($activeServiceBulls) {
            $birthDate = $c->lineage?->birth_date;
            $formattedBirth = null;
            if ($birthDate instanceof \DateTimeInterface) {
                $formattedBirth = $birthDate->format('Y-m-d');
            } elseif (is_string($birthDate)) {
                $formattedBirth = substr($birthDate, 0, 10);
            }

            return [
                'id' => (int) $c->id,
                'caravan_number' => (string) ($c->identification ?? 'Cría sin caravana'),
                'tag_number' => $c->identification,
                'category_id' => $c->category_id ? (int) $c->category_id : null,
                'category_code' => $c->category?->code,
                'category_name' => $c->category?->name,
                'birth_date' => $formattedBirth,
                'blocked_by' => $activeServiceBulls[(int) $c->id] ?? null,
            ];
        })->values()->all();
    }

    public function countCandidatesByBatch(int $companyId, int $batchId): int
    {
        return $this->candidateQuery($companyId, $batchId)->count();
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

