<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Interfaces\IServiceOrderBullHistoryRepository;
use Illuminate\Support\Facades\DB;

final class EloquentServiceOrderBullHistoryRepository implements IServiceOrderBullHistoryRepository
{
    public function findOrder(int $serviceOrderId, int $companyId): ?array
    {
        $row = DB::table('service_orders as so')
            ->leftJoin('batches as b', 'b.id', '=', 'so.batch_id')
            ->where('so.id', $serviceOrderId)
            ->where('so.company_id', $companyId)
            ->first([
                'so.id', 'so.code', 'so.status', 'so.service_method', 'so.service_type', 'so.batch_id', 'b.name as batch_name',
                'so.actual_start_date', 'so.planned_start_date', 'so.actual_end_date', 'so.planned_end_date',
            ]);

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'code' => (string) $row->code,
            'status' => (string) $row->status,
            'service_method' => (string) ($row->service_method ?: 'NATURAL'),
            'service_type' => $row->service_type,
            'batch_id' => (int) $row->batch_id,
            'batch_name' => $row->batch_name,
            'start_date' => self::day($row->actual_start_date ?? $row->planned_start_date),
            'end_date' => self::day($row->actual_end_date ?? $row->planned_end_date),
        ];
    }

    public function findBullStays(int $serviceOrderId): array
    {
        return DB::table('service_order_males as m')
            ->join('caravans as bull', 'bull.id', '=', 'm.male_caravan_id')
            ->leftJoin('animal_categories as cat', 'cat.id', '=', 'bull.category_id')
            ->leftJoin('breeds as br', 'br.id', '=', 'bull.breed_id')
            ->leftJoin('batches as dest', 'dest.id', '=', 'm.exit_destination_batch_id')
            ->where('m.service_order_id', $serviceOrderId)
            ->orderBy('m.joined_at')
            ->orderBy('m.id')
            ->get([
                'm.male_caravan_id', 'bull.identification', 'cat.name as category', 'br.name as breed', 'm.status',
                'm.joined_at', 'm.retired_at', 'm.sanitary_status_at_entry', 'm.snapshot_scrotal_circumference',
                'm.service_capacity', 'm.exit_reason', 'm.exit_notes', 'dest.name as exit_destination_batch',
            ])
            ->map(static fn (object $row): array => [
                'bull_id' => (int) $row->male_caravan_id,
                'identification' => (string) $row->identification,
                'category' => $row->category,
                'breed' => $row->breed,
                'status' => (string) $row->status,
                'joined_at' => self::day($row->joined_at),
                'retired_at' => self::day($row->retired_at),
                'sanitary_status_at_entry' => $row->sanitary_status_at_entry,
                'scrotal_circumference' => $row->snapshot_scrotal_circumference !== null ? (float) $row->snapshot_scrotal_circumference : null,
                'service_capacity' => $row->service_capacity,
                'exit_reason' => $row->exit_reason,
                'exit_notes' => $row->exit_notes,
                'exit_destination_batch' => $row->exit_destination_batch,
            ])
            ->values()
            ->all();
    }

    public function findReplacements(int $serviceOrderId): array
    {
        return DB::table('service_order_bull_replacements as r')
            ->leftJoin('batches as dest', 'dest.id', '=', 'r.destination_batch_id')
            ->where('r.service_order_id', $serviceOrderId)
            ->orderBy('r.replacement_date')
            ->get(['r.retired_male_caravan_id', 'r.replacement_male_caravan_id', 'r.replacement_date', 'r.reason', 'r.notes', 'dest.name as destination_batch'])
            ->map(static fn (object $row): array => [
                'retired_bull_id' => (int) $row->retired_male_caravan_id,
                'replacement_bull_id' => (int) $row->replacement_male_caravan_id,
                'date' => (string) self::day($row->replacement_date),
                'reason' => (string) $row->reason,
                'notes' => $row->notes,
                'destination_batch' => $row->destination_batch,
            ])
            ->values()
            ->all();
    }

    public function findGestationSires(int $serviceOrderId): array
    {
        return DB::table('gestation_sires as gs')
            ->join('caravan_gestations as g', 'g.id', '=', 'gs.gestation_id')
            ->join('caravans as cow', 'cow.id', '=', 'g.caravan_id')
            ->where('g.service_order_id', $serviceOrderId)
            ->orderBy('cow.identification')
            ->get(['gs.sire_id', 'g.caravan_id', 'cow.identification', 'g.id as gestation_id', 'gs.is_confirmed', 'g.start_date'])
            ->map(static fn (object $row): array => [
                'bull_id' => (int) $row->sire_id,
                'female_id' => (int) $row->caravan_id,
                'female_identification' => (string) $row->identification,
                'gestation_id' => (int) $row->gestation_id,
                'is_confirmed' => (bool) $row->is_confirmed,
                'start_date' => self::day($row->start_date),
            ])
            ->values()
            ->all();
    }

    private static function day(mixed $value): ?string
    {
        return $value !== null ? substr((string) $value, 0, 10) : null;
    }
}
