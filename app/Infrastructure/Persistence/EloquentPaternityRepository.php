<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Interfaces\IPaternityRepository;
use Illuminate\Support\Facades\DB;

class EloquentPaternityRepository implements IPaternityRepository
{
    public function findCalves(int $companyId, ?int $serviceOrderId = null, ?string $search = null, ?array $calfIds = null): array
    {
        $rows = DB::table('caravan_lineage as l')
            ->join('caravans as calf', 'calf.id', '=', 'l.caravan_id')
            ->leftJoin('caravans as mother', 'mother.id', '=', 'l.mother_id')
            ->leftJoin('batches as b', 'b.id', '=', 'calf.batch_id')
            ->leftJoin('caravan_gestations as g', 'g.id', '=', 'l.gestation_id')
            ->leftJoin('service_orders as so', 'so.id', '=', 'g.service_order_id')
            ->where('calf.company_id', $companyId)
            ->when($calfIds === null, fn ($q) => $q->whereNull('l.father_id'))
            ->when($calfIds !== null, fn ($q) => $q->whereIn('l.caravan_id', $calfIds))
            ->when($serviceOrderId !== null, fn ($q) => $q->where('so.id', $serviceOrderId))
            ->when($search !== null && trim($search) !== '', function ($q) use ($search) {
                $like = '%' . trim((string) $search) . '%';
                $q->where(fn ($w) => $w->where('calf.identification', 'like', $like)->orWhere('mother.identification', 'like', $like));
            })
            ->orderBy('so.code')
            ->orderBy('l.birth_date')
            ->orderBy('mother.identification')
            ->get([
                'l.caravan_id as calf_id',
                'calf.identification as calf_identification',
                'calf.sex as calf_sex',
                'l.birth_date',
                'l.father_id',
                'l.mother_id',
                'mother.identification as mother_identification',
                'calf.batch_id',
                'b.name as batch_name',
                'l.gestation_id',
                'so.id as service_order_id',
                'so.code as service_order_code',
            ]);

        $siresByGestation = DB::table('gestation_sires as gs')
            ->join('caravans as bull', 'bull.id', '=', 'gs.sire_id')
            ->whereIn('gs.gestation_id', $rows->pluck('gestation_id')->filter()->unique()->all())
            ->orderBy('bull.identification')
            ->get(['gs.gestation_id', 'gs.sire_id', 'bull.identification'])
            ->groupBy('gestation_id');

        return $rows->map(fn ($row): array => [
            'calf_id' => (int) $row->calf_id,
            'calf_identification' => $row->calf_identification,
            'calf_sex' => $row->calf_sex,
            'birth_date' => substr((string) $row->birth_date, 0, 10),
            'father_id' => $row->father_id !== null ? (int) $row->father_id : null,
            'mother_id' => (int) $row->mother_id,
            'mother_identification' => $row->mother_identification,
            'batch_id' => $row->batch_id !== null ? (int) $row->batch_id : null,
            'batch_name' => $row->batch_name,
            'gestation_id' => $row->gestation_id !== null ? (int) $row->gestation_id : null,
            'service_order_id' => $row->service_order_id !== null ? (int) $row->service_order_id : null,
            'service_order_code' => $row->service_order_code,
            'gestation_sires' => ($siresByGestation[$row->gestation_id] ?? collect())
                ->map(fn ($sire): array => ['id' => (int) $sire->sire_id, 'identification' => (string) $sire->identification])
                ->values()
                ->all(),
        ])->all();
    }

    public function findBullStays(array $serviceOrderIds): array
    {
        $stays = [];
        $rows = DB::table('service_order_males as m')
            ->join('caravans as bull', 'bull.id', '=', 'm.male_caravan_id')
            ->whereIn('m.service_order_id', $serviceOrderIds)
            ->get(['m.service_order_id', 'm.male_caravan_id', 'bull.identification', 'm.joined_at', 'm.retired_at']);

        foreach ($rows as $row) {
            $stays[(int) $row->service_order_id][(int) $row->male_caravan_id] = [
                'identification' => (string) $row->identification,
                'joined_at' => $row->joined_at !== null ? substr((string) $row->joined_at, 0, 10) : null,
                'retired_at' => $row->retired_at !== null ? substr((string) $row->retired_at, 0, 10) : null,
            ];
        }

        return $stays;
    }

    public function confirm(int $calfId, int $fatherId, string $method, ?string $notes, ?int $gestationId): void
    {
        $now = now();

        DB::table('caravan_lineage')->where('caravan_id', $calfId)->update([
            'father_id' => $fatherId,
            'sire_assigned_at' => $now,
            'sire_identification_method' => $method,
            'sire_notes' => $notes,
            'updated_at' => $now,
        ]);

        if ($gestationId === null) {
            return;
        }

        DB::table('gestation_sires')->where('gestation_id', $gestationId)->update(['is_confirmed' => false, 'updated_at' => $now]);
        $updated = DB::table('gestation_sires')
            ->where('gestation_id', $gestationId)
            ->where('sire_id', $fatherId)
            ->update(['is_confirmed' => true, 'updated_at' => $now]);
        if ($updated === 0) {
            DB::table('gestation_sires')->insert([
                'gestation_id' => $gestationId,
                'sire_id' => $fatherId,
                'is_confirmed' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
