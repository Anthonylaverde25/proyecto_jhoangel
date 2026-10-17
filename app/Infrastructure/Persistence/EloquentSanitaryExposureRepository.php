<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\ExposedFemaleEntry;
use App\Core\Entities\SanitaryExposureAlertEntity;
use App\Core\Enums\ServiceOrderMaleStatus;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Interfaces\ISanitaryExposureRepository;
use App\Core\ValueObjects\SanitaryExposure\BullParticipation;
use App\Core\ValueObjects\SanitaryExposure\ContagiousFinding;
use App\Core\ValueObjects\SanitaryExposure\FemaleExposure;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentSanitaryExposureRepository implements ISanitaryExposureRepository
{
    public function bullParticipations(int $bullId, int $companyId): array
    {
        return DB::table('service_order_males')
            ->join('service_orders', 'service_orders.id', '=', 'service_order_males.service_order_id')
            ->where('service_orders.company_id', $companyId)
            ->where('service_order_males.male_caravan_id', $bullId)
            ->whereNotNull('service_order_males.joined_at')
            ->get([
                'service_order_males.service_order_id',
                'service_orders.is_controlled_service',
                'service_order_males.joined_at',
                'service_order_males.retired_at',
            ])
            ->map(fn ($row) => new BullParticipation(
                serviceOrderId: (int) $row->service_order_id,
                isControlledService: (bool) $row->is_controlled_service,
                joinedAt: $this->date((string) $row->joined_at),
                retiredAt: $row->retired_at !== null ? $this->date((string) $row->retired_at) : null
            ))
            ->values()
            ->all();
    }

    public function femaleExposures(array $serviceOrderIds): array
    {
        if ($serviceOrderIds === []) {
            return [];
        }

        return DB::table('service_order_females')
            ->whereIn('service_order_id', $serviceOrderIds)
            ->whereNotNull('exposed_from')
            ->get(['service_order_id', 'female_caravan_id', 'exposed_from', 'exposed_until', 'assigned_male_caravan_id'])
            ->map(fn ($row) => new FemaleExposure(
                serviceOrderId: (int) $row->service_order_id,
                femaleId: (int) $row->female_caravan_id,
                exposedFrom: $this->date((string) $row->exposed_from),
                exposedUntil: $row->exposed_until !== null ? $this->date((string) $row->exposed_until) : null,
                assignedMaleId: $row->assigned_male_caravan_id !== null ? (int) $row->assigned_male_caravan_id : null
            ))
            ->values()
            ->all();
    }

    public function findByFinding(int $bullId, string $pathogenCode, DateTimeImmutable $detectedOn): ?array
    {
        $row = DB::table('sanitary_exposure_alerts')
            ->where('bull_caravan_id', $bullId)
            ->where('pathogen_code', $pathogenCode)
            ->whereDate('detected_on', $detectedOn->format('Y-m-d'))
            ->first(['id', 'status']);

        return $row !== null ? ['id' => (int) $row->id, 'status' => (string) $row->status] : null;
    }

    public function create(int $companyId, int $bullId, ContagiousFinding $finding, string $detail): int
    {
        return (int) DB::table('sanitary_exposure_alerts')->insertGetId([
            'company_id' => $companyId,
            'bull_caravan_id' => $bullId,
            'pathogen_code' => $finding->pathogenCode,
            'pathogen_name' => $finding->pathogenName
                ?? DB::table('pathogens')->where('code', $finding->pathogenCode)->value('name'),
            'detected_on' => $finding->detectedOn->format('Y-m-d'),
            'risk_window_from' => $finding->riskWindowFrom->format('Y-m-d'),
            'detail' => $detail,
            'status' => SanitaryExposureAlertEntity::STATUS_OPEN,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    public function updateDetail(int $alertId, string $detail): void
    {
        DB::table('sanitary_exposure_alerts')
            ->where('id', $alertId)
            ->update(['detail' => $detail, 'updated_at' => Carbon::now()]);
    }

    public function syncFemales(int $alertId, int $companyId, array $overlaps): void
    {
        foreach ($overlaps as $overlap) {
            $key = [
                'sanitary_exposure_alert_id' => $alertId,
                'female_caravan_id' => $overlap->femaleId,
                'service_order_id' => $overlap->serviceOrderId,
            ];
            $values = [
                'overlap_from' => $overlap->from->format('Y-m-d'),
                'overlap_until' => $overlap->until?->format('Y-m-d'),
                'updated_at' => Carbon::now(),
            ];

            $exists = DB::table('sanitary_exposure_alert_females')->where($key)->exists();
            if ($exists) {
                DB::table('sanitary_exposure_alert_females')->where($key)->update($values);
            } else {
                DB::table('sanitary_exposure_alert_females')->insert($key + $values + [
                    'company_id' => $companyId,
                    'created_at' => Carbon::now(),
                ]);
            }
        }
    }

    public function bullTag(int $bullId): string
    {
        return (string) (DB::table('caravans')->where('id', $bullId)->value('identification') ?? "#{$bullId}");
    }

    public function list(int $companyId, ?string $status): array
    {
        return $this->baseQuery($companyId)
            ->when($status !== null, fn ($q) => $q->where('sea.status', $status))
            ->orderByRaw("CASE WHEN sea.status = 'OPEN' THEN 0 ELSE 1 END")
            ->orderByDesc('sea.detected_on')
            ->get()
            ->map(fn ($row) => $this->toEntity($row, []))
            ->values()
            ->all();
    }

    public function find(int $alertId, int $companyId): ?SanitaryExposureAlertEntity
    {
        $row = $this->baseQuery($companyId)->where('sea.id', $alertId)->first();
        if ($row === null) {
            return null;
        }

        $females = DB::table('sanitary_exposure_alert_females as seaf')
            ->join('caravans', 'caravans.id', '=', 'seaf.female_caravan_id')
            ->join('service_orders', 'service_orders.id', '=', 'seaf.service_order_id')
            ->leftJoin('batches', 'batches.id', '=', 'caravans.batch_id')
            ->where('seaf.sanitary_exposure_alert_id', $alertId)
            ->orderBy('caravans.identification')
            ->get([
                'seaf.female_caravan_id',
                'caravans.identification',
                'caravans.batch_id',
                'batches.name as batch_name',
                'seaf.service_order_id',
                'service_orders.code as order_code',
                'seaf.overlap_from',
                'seaf.overlap_until',
                'seaf.reviewed_at',
                'seaf.review_note',
                DB::raw('EXISTS (SELECT 1 FROM caravan_gestations cg WHERE cg.caravan_id = caravans.id AND cg.is_current = 1) as is_pregnant'),
            ])
            ->map(fn ($f) => new ExposedFemaleEntry(
                femaleId: (int) $f->female_caravan_id,
                femaleTag: (string) $f->identification,
                currentBatchId: $f->batch_id !== null ? (int) $f->batch_id : null,
                currentBatchName: $f->batch_name,
                isPregnant: (bool) $f->is_pregnant,
                serviceOrderId: (int) $f->service_order_id,
                serviceOrderCode: (string) $f->order_code,
                overlapFrom: $this->date((string) $f->overlap_from),
                overlapUntil: $f->overlap_until !== null ? $this->date((string) $f->overlap_until) : null,
                reviewedAt: $f->reviewed_at !== null ? new DateTimeImmutable((string) $f->reviewed_at) : null,
                reviewNote: $f->review_note
            ))
            ->values()
            ->all();

        return $this->toEntity($row, $females);
    }

    public function markReviewed(int $alertId, array $femaleIds, ?int $userId, ?string $note, DateTimeImmutable $at): int
    {
        if ($femaleIds === []) {
            return 0;
        }

        return DB::table('sanitary_exposure_alert_females')
            ->where('sanitary_exposure_alert_id', $alertId)
            ->whereIn('female_caravan_id', $femaleIds)
            ->whereNull('reviewed_at')
            ->update([
                'reviewed_at' => $at->format('Y-m-d H:i:s'),
                'reviewed_by_user_id' => $userId,
                'review_note' => $note,
                'updated_at' => Carbon::now(),
            ]);
    }

    public function resolve(int $alertId, ?int $userId, string $resolution, DateTimeImmutable $at): void
    {
        DB::table('sanitary_exposure_alerts')
            ->where('id', $alertId)
            ->update([
                'status' => SanitaryExposureAlertEntity::STATUS_RESOLVED,
                'resolution' => $resolution,
                'resolved_by_user_id' => $userId,
                'resolved_at' => $at->format('Y-m-d H:i:s'),
                'updated_at' => Carbon::now(),
            ]);
    }

    public function openExposuresForFemales(array $femaleIds, int $companyId): array
    {
        if ($femaleIds === []) {
            return [];
        }

        $byFemale = [];
        DB::table('sanitary_exposure_alert_females as seaf')
            ->join('sanitary_exposure_alerts as sea', 'sea.id', '=', 'seaf.sanitary_exposure_alert_id')
            ->join('caravans as bull', 'bull.id', '=', 'sea.bull_caravan_id')
            ->where('sea.company_id', $companyId)
            ->where('sea.status', SanitaryExposureAlertEntity::STATUS_OPEN)
            ->whereIn('seaf.female_caravan_id', $femaleIds)
            ->get(['seaf.female_caravan_id', 'sea.id', 'bull.identification', 'sea.pathogen_name', 'sea.pathogen_code', 'seaf.reviewed_at'])
            ->each(function ($row) use (&$byFemale): void {
                $alertId = (int) $row->id;
                $femaleId = (int) $row->female_caravan_id;
                // A female exposed in two orders of the same alert is listed once.
                $byFemale[$femaleId][$alertId] = [
                    'alert_id' => $alertId,
                    'bull_tag' => (string) $row->identification,
                    'pathogen' => (string) ($row->pathogen_name ?? $row->pathogen_code),
                    'reviewed' => ($byFemale[$femaleId][$alertId]['reviewed'] ?? true) && $row->reviewed_at !== null,
                ];
            });

        return array_map('array_values', $byFemale);
    }

    public function openAlertsInBatch(int $batchId, int $companyId): array
    {
        return DB::table('sanitary_exposure_alert_females as seaf')
            ->join('sanitary_exposure_alerts as sea', 'sea.id', '=', 'seaf.sanitary_exposure_alert_id')
            ->join('caravans as female', 'female.id', '=', 'seaf.female_caravan_id')
            ->join('caravans as bull', 'bull.id', '=', 'sea.bull_caravan_id')
            ->where('sea.company_id', $companyId)
            ->where('sea.status', SanitaryExposureAlertEntity::STATUS_OPEN)
            ->where('female.batch_id', $batchId)
            ->groupBy('sea.id', 'bull.identification', 'sea.pathogen_name', 'sea.pathogen_code')
            ->orderBy('sea.id')
            ->get([
                'sea.id',
                'bull.identification',
                'sea.pathogen_name',
                'sea.pathogen_code',
                DB::raw('COUNT(DISTINCT seaf.female_caravan_id) as females'),
            ])
            ->map(fn ($row) => [
                'alert_id' => (int) $row->id,
                'bull_tag' => (string) $row->identification,
                'pathogen' => (string) ($row->pathogen_name ?? $row->pathogen_code),
                'count' => (int) $row->females,
            ])
            ->values()
            ->all();
    }

    private function baseQuery(int $companyId): \Illuminate\Database\Query\Builder
    {
        return DB::table('sanitary_exposure_alerts as sea')
            ->join('caravans as bull', 'bull.id', '=', 'sea.bull_caravan_id')
            ->leftJoin('users', 'users.id', '=', 'sea.resolved_by_user_id')
            ->where('sea.company_id', $companyId)
            ->select([
                'sea.*',
                'bull.identification as bull_tag',
                'users.name as resolved_by_name',
                DB::raw('(SELECT COUNT(DISTINCT f.female_caravan_id) FROM sanitary_exposure_alert_females f WHERE f.sanitary_exposure_alert_id = sea.id) as females_count'),
                DB::raw('(SELECT COUNT(DISTINCT f.female_caravan_id) FROM sanitary_exposure_alert_females f WHERE f.sanitary_exposure_alert_id = sea.id AND f.reviewed_at IS NOT NULL) as reviewed_count'),
            ]);
    }

    /**
     * @param list<ExposedFemaleEntry> $females
     */
    private function toEntity(object $row, array $females): SanitaryExposureAlertEntity
    {
        return new SanitaryExposureAlertEntity(
            id: (int) $row->id,
            bullId: (int) $row->bull_caravan_id,
            bullTag: (string) $row->bull_tag,
            bullActiveOrder: $this->activeOrderOf((int) $row->bull_caravan_id),
            pathogenCode: (string) $row->pathogen_code,
            pathogenName: $row->pathogen_name,
            detectedOn: $this->date((string) $row->detected_on),
            riskWindowFrom: $this->date((string) $row->risk_window_from),
            detail: (string) $row->detail,
            status: (string) $row->status,
            resolution: $row->resolution,
            resolvedAt: $row->resolved_at !== null ? new DateTimeImmutable((string) $row->resolved_at) : null,
            resolvedByName: $row->resolved_by_name,
            femalesCount: (int) $row->females_count,
            reviewedCount: (int) $row->reviewed_count,
            females: $females
        );
    }

    /**
     * @return array{service_order_id: int, code: string, batch_id: int}|null
     */
    private function activeOrderOf(int $bullId): ?array
    {
        $row = DB::table('service_order_males')
            ->join('service_orders', 'service_orders.id', '=', 'service_order_males.service_order_id')
            ->where('service_order_males.male_caravan_id', $bullId)
            ->where('service_order_males.status', ServiceOrderMaleStatus::ACTIVE->value)
            ->where('service_orders.status', ServiceOrderStatus::APPROVED->value)
            ->first(['service_orders.id', 'service_orders.code', 'service_orders.batch_id']);

        return $row !== null
            ? ['service_order_id' => (int) $row->id, 'code' => (string) $row->code, 'batch_id' => (int) $row->batch_id]
            : null;
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable(substr($value, 0, 10));
    }
}
