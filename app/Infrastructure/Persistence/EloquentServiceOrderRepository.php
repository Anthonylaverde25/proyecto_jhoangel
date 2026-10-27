<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\ServiceOrderMapper;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\FemaleReproductiveVerdict;
use App\Core\Enums\NotDiagnosedReason;
use App\Core\Enums\ReproductiveAptitudeStatus;
use App\Core\Enums\ServiceExposureExitReason;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderDiagnosisRound;
use App\Models\ServiceOrderFemale;
use App\Models\ServiceOrderFemaleDiagnosis;
use App\Models\ServiceOrderMale;
use Illuminate\Support\Facades\DB;

class EloquentServiceOrderRepository implements IServiceOrderRepository
{
    private const RELATIONS = [
        'batch',
        'males',
        'females',
        'lastInseminations',
        'history',
        'serviceOrderMales',
        'bullReplacements.retiredMaleCaravan.currentWeight',
        'bullReplacements.replacementMaleCaravan.currentWeight',
        'bullReplacements.replacementMaleCaravan.bullHealthEvaluation',
        'bullReplacements.destinationBatch',
        'bullReplacements.user',
    ];

    public function save(ServiceOrderEntity $entity, int $actionUserId, ?string $reason = null): ServiceOrderEntity
    {
        return DB::transaction(function () use ($entity, $actionUserId, $reason) {
            $isNew = $entity->getId() === null;
            $oldStatus = null;

            if (!$isNew) {
                $model = ServiceOrder::find($entity->getId());
                if ($model !== null) {
                    $oldStatus = $model->status;
                }
            } else {
                $model = new ServiceOrder();
            }

            $model = ServiceOrderMapper::toModel($entity, $model);
            $model->save();

            // Sync pivote tables with company_id
            $malePivotData = [];
            if ($entity->getMaleDetails() !== []) {
                foreach ($entity->getMaleDetails() as $d) {
                    $malePivotData[(int)$d['male_caravan_id']] = [
                        'company_id'                     => $model->company_id,
                        'status'                         => $d['status'] ?? 'ACTIVE',
                        'retired_at'                     => $d['retired_at'] ?? null,
                        'snapshot_scrotal_circumference' => $d['scrotal_circumference'] ?? null,
                        'service_capacity'               => $d['service_capacity'] ?? null,
                    ];
                }
            } else {
                foreach ($entity->getAllMaleCaravanIds() as $maleId) {
                    $malePivotData[$maleId] = [
                        'company_id' => $model->company_id,
                        'status'     => 'ACTIVE',
                    ];
                }
            }
            $model->males()->sync($malePivotData);

            $femalePivotData = [];
            foreach ($entity->getFemaleCaravanIds() as $femaleId) {
                $pivotData = ['company_id' => $model->company_id];

                if ($entity->isControlledService()) {
                    foreach ($entity->getFemaleSireAssignments() as $assignment) {
                        if ((int)$assignment['female_caravan_id'] === (int)$femaleId) {
                            $pivotData['assigned_male_caravan_id'] = (int)$assignment['assigned_male_caravan_id'];
                            break;
                        }
                    }
                }

                $femalePivotData[$femaleId] = $pivotData;
            }
            $model->females()->sync($femalePivotData);

            // Check if status changed or it is a new record to log history
            if ($isNew || $oldStatus !== $model->status) {
                $model->history()->create([
                    'company_id' => $model->company_id,
                    'from_status' => $oldStatus,
                    'to_status' => $model->status,
                    'action_user_id' => $actionUserId,
                    'action_reason' => $reason,
                ]);
            }

            $this->syncExposureWithStatus($model);

            // Load relations to build the entity correctly
            $model->load(self::RELATIONS);

            return ServiceOrderMapper::toEntity($model);
        });
    }

    /**
     * Exposure follows the order lifecycle: an approved order exposes the females that were
     * still pending (planned DRAFT orders), and a closed order ends every open exposure.
     */
    private function syncExposureWithStatus(ServiceOrder $model): void
    {
        if ($model->status === ServiceOrderStatus::APPROVED->value) {
            $startDate = $model->actual_start_date?->format('Y-m-d') ?? now()->format('Y-m-d');
            ServiceOrderFemale::withoutGlobalScopes()
                ->where('service_order_id', $model->id)
                ->whereNull('exposed_from')
                ->update(['exposed_from' => $startDate]);
            // The bulls of a planned order join the females when it is approved.
            ServiceOrderMale::withoutGlobalScopes()
                ->where('service_order_id', $model->id)
                ->whereNull('joined_at')
                ->update(['joined_at' => $startDate]);

            return;
        }

        // An AI service ended from the menu closes its exposures like a closed order (repaso bulls
        // withdrawn already closed theirs).
        if (in_array($model->status, [ServiceOrderStatus::SUCCESS->value, ServiceOrderStatus::CANCELLED->value, ServiceOrderStatus::SERVICE_ENDED->value], true)) {
            $endDate = $model->actual_end_date?->format('Y-m-d') ?? now()->format('Y-m-d');
            $this->closeOpenExposures((int) $model->id, $endDate, ServiceExposureExitReason::SERVICE_CLOSED);
        }
    }

    public function findById(int $id, int $companyId): ?ServiceOrderEntity
    {
        $model = ServiceOrder::with(self::RELATIONS)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        return $model ? ServiceOrderMapper::toEntity($model) : null;
    }

    public function findActiveOrdersByCaravans(array $caravanIds, int $companyId): array
    {
        if (empty($caravanIds)) {
            return [];
        }

        $activeStatuses = [
            ServiceOrderStatus::DRAFT->value,
            ServiceOrderStatus::APPROVED->value,
        ];

        // Search caravans assigned as ACTIVE males in active service orders
        $maleConflicts = DB::table('service_order_males')
            ->join('service_orders', 'service_order_males.service_order_id', '=', 'service_orders.id')
            ->where('service_orders.company_id', $companyId)
            ->whereIn('service_orders.status', $activeStatuses)
            ->where('service_order_males.status', 'ACTIVE')
            ->whereIn('service_order_males.male_caravan_id', $caravanIds)
            ->pluck('service_order_males.male_caravan_id')
            ->toArray();

        // Search caravans assigned as females in active service orders
        $femaleConflicts = DB::table('service_order_females')
            ->join('service_orders', 'service_order_females.service_order_id', '=', 'service_orders.id')
            ->where('service_orders.company_id', $companyId)
            ->whereIn('service_orders.status', $activeStatuses)
            ->whereIn('service_order_females.female_caravan_id', $caravanIds)
            ->whereNull('service_order_females.exposed_until')
            ->pluck('service_order_females.female_caravan_id')
            ->toArray();

        return array_unique(array_merge($maleConflicts, $femaleConflicts));
    }

    public function verifyAnimalsSexAndOwnership(array $caravanIds, int $companyId): array
    {
        if (empty($caravanIds)) {
            return [];
        }

        return DB::table('caravans')
            ->where('company_id', $companyId)
            ->whereIn('id', $caravanIds)
            ->pluck('sex', 'id')
            ->toArray();
    }

    public function moveBullsToBatch(array $bullIds, int $batchId, int $companyId, string $movementDate, string $orderCode): array
    {
        $batch = Batch::withoutGlobalScopes()->with('farm')->find($batchId);
        $batchName = (string) $batch?->name;
        // The movement carries the RENSPA of the destination: its farm's, or the company's for a
        // batch without a farm.
        $renspa = $batch?->farm?->renspa;
        if ($renspa === null || $renspa === '') {
            $renspa = DB::table('companies')->where('id', $companyId)->value('renspa');
        }
        $renspa = $renspa !== null && $renspa !== '' ? (string) $renspa : 'NO_DEFINIDO';
        $donorBatchIds = [];

        $bulls = Caravan::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', $bullIds)
            ->get();

        foreach ($bulls as $bull) {
            $fromBatchId = $bull->batch_id !== null ? (int) $bull->batch_id : null;
            if ($fromBatchId === $batchId) {
                continue;
            }

            if ($fromBatchId !== null) {
                $donorBatchIds[$fromBatchId] = true;
            }

            $bull->batch_id = $batchId;
            $bull->save();

            // Eloquent create, so the movement observers (service exposure) see it.
            CaravanMovement::create([
                'company_id'    => $companyId,
                'caravan_id'    => $bull->id,
                'renspa'        => $renspa,
                'type'          => 'TRANSFER',
                'movement_date' => $movementDate,
                'from_batch_id' => $fromBatchId,
                'to_batch_id'   => $batchId,
                'observations'  => "Ingreso de reproductor a servicio en '{$batchName}' (Orden {$orderCode})",
            ]);
        }

        return array_map('intval', array_keys($donorBatchIds));
    }

    public function lockLastCodeWithPrefix(int $companyId, string $prefix): ?string
    {
        $code = ServiceOrder::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', 'like', $prefix . '%')
            ->orderByDesc('code')
            ->lockForUpdate()
            ->value('code');

        return $code !== null ? (string) $code : null;
    }

    public function findActiveServiceOnBatch(int $batchId): ?array
    {
        $row = ServiceOrder::withoutGlobalScopes()
            ->where('batch_id', $batchId)
            ->whereIn('status', [ServiceOrderStatus::DRAFT->value, ServiceOrderStatus::APPROVED->value])
            ->first(['id', 'status', 'is_controlled_service', 'service_method']);

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'status' => (string) $row->status,
            'is_controlled_service' => (bool) $row->is_controlled_service,
            'service_method' => (string) ($row->service_method ?? 'NATURAL'),
        ];
    }

    public function findByBatch(int $batchId, int $companyId): array
    {
        return ServiceOrder::with(self::RELATIONS)
            ->where('company_id', $companyId)
            ->where('batch_id', $batchId)
            ->orderByDesc('planned_start_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ServiceOrder $model) => ServiceOrderMapper::toEntity($model))
            ->all();
    }

    public function isFemaleInOrder(int $orderId, int $femaleId): bool
    {
        return ServiceOrderFemale::withoutGlobalScopes()
            ->where('service_order_id', $orderId)
            ->where('female_caravan_id', $femaleId)
            ->exists();
    }

    public function openFemaleExposure(int $orderId, int $companyId, int $femaleId, string $date, bool $joinedAfterStart): void
    {
        $existing = ServiceOrderFemale::withoutGlobalScopes()
            ->where('service_order_id', $orderId)
            ->where('female_caravan_id', $femaleId)
            ->first();

        if ($existing !== null) {
            // Back in the batch during the same service: the row is unique per order and female,
            // so it is reopened. The intermediate exit stays in caravan_movements.
            $existing->update([
                'exposed_from'  => $existing->exposed_from ?? $date,
                'exposed_until' => null,
                'exit_reason'   => null,
            ]);

            return;
        }

        ServiceOrderFemale::create([
            'company_id'         => $companyId,
            'service_order_id'   => $orderId,
            'female_caravan_id'  => $femaleId,
            'reproductive_status' => 'UNCHECKED',
            'exposed_from'       => $date,
            'joined_after_start' => $joinedAfterStart,
        ]);
    }

    public function closeFemaleExposure(int $orderId, int $femaleId, string $date, ServiceExposureExitReason $reason): bool
    {
        return ServiceOrderFemale::withoutGlobalScopes()
            ->where('service_order_id', $orderId)
            ->where('female_caravan_id', $femaleId)
            ->whereNotNull('exposed_from')
            ->whereNull('exposed_until')
            ->update(['exposed_until' => $date, 'exit_reason' => $reason->value]) > 0;
    }

    public function closeOpenExposures(int $orderId, string $date, ServiceExposureExitReason $reason): int
    {
        return ServiceOrderFemale::withoutGlobalScopes()
            ->where('service_order_id', $orderId)
            ->whereNotNull('exposed_from')
            ->whereNull('exposed_until')
            ->update(['exposed_until' => $date, 'exit_reason' => $reason->value]);
    }

    public function listAll(int $companyId): array
    {
        $models = ServiceOrder::with(self::RELATIONS)
            ->where('company_id', $companyId)
            ->orderBy('id', 'desc')
            ->get();

        return $models->map(fn($model) => ServiceOrderMapper::toEntity($model))->toArray();
    }

    public function recordBullSanitaryStatusAtEntry(int $orderId, int $maleId, ?ReproductiveAptitudeStatus $status): void
    {
        ServiceOrderMale::withoutGlobalScopes()
            ->where('service_order_id', $orderId)
            ->where('male_caravan_id', $maleId)
            ->update(['sanitary_status_at_entry' => $status?->value]);
    }

    public function findOrCreateDiagnosisRound(int $orderId, int $companyId, string $roundDate): int
    {
        $round = ServiceOrderDiagnosisRound::withoutGlobalScopes()->firstOrCreate(
            ['service_order_id' => $orderId, 'round_date' => substr($roundDate, 0, 10)],
            ['company_id' => $companyId]
        );

        return (int) $round->id;
    }

    public function findFemaleVerdict(int $orderId, int $femaleId): ?array
    {
        $row = ServiceOrderFemale::withoutGlobalScopes()
            ->where('service_order_id', $orderId)
            ->where('female_caravan_id', $femaleId)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'reproductive_status' => (string) ($row->reproductive_status ?? FemaleReproductiveVerdict::UNCHECKED->value),
            'diagnosed_at' => $row->diagnosed_at?->format('Y-m-d'),
            'exposed_until' => $row->exposed_until?->format('Y-m-d'),
            'gestation_id' => $row->gestation_id !== null ? (int) $row->gestation_id : null,
        ];
    }

    public function findLatestDiagnosedVerdict(int $orderId, int $femaleId): ?FemaleReproductiveVerdict
    {
        $verdict = ServiceOrderFemaleDiagnosis::withoutGlobalScopes()
            ->where('service_order_id', $orderId)
            ->where('female_caravan_id', $femaleId)
            ->orderByDesc('id')
            ->value('verdict');

        return $verdict !== null ? FemaleReproductiveVerdict::tryFrom((string) $verdict) : null;
    }

    public function recordFemaleVerdict(
        int $orderId,
        int $companyId,
        int $femaleId,
        int $roundId,
        FemaleReproductiveVerdict $verdict,
        string $diagnosedAt,
        ?int $userId,
        ?int $gestationId = null,
        ?string $gestationStage = null,
        ?float $gestationMonths = null,
        ?NotDiagnosedReason $notDiagnosedReason = null,
        ?string $notDiagnosedNotes = null
    ): void {
        $row = ServiceOrderFemale::withoutGlobalScopes()
            ->where('service_order_id', $orderId)
            ->where('female_caravan_id', $femaleId)
            ->firstOrFail();

        ServiceOrderFemaleDiagnosis::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'service_order_id' => $orderId,
            'diagnosis_round_id' => $roundId,
            'service_order_female_id' => $row->id,
            'female_caravan_id' => $femaleId,
            'verdict' => $verdict->value,
            'gestation_stage' => $gestationStage,
            'gestation_months' => $gestationMonths,
            'gestation_id' => $gestationId,
            'not_diagnosed_reason' => $notDiagnosedReason?->value,
            'not_diagnosed_notes' => $notDiagnosedNotes,
            'diagnosed_by_user_id' => $userId,
        ]);

        $row->update([
            'reproductive_status' => $verdict->value,
            'diagnosed_at' => substr($diagnosedAt, 0, 10),
            'diagnosed_by_user_id' => $userId,
            'gestation_id' => $gestationId,
            'not_diagnosed_reason' => $notDiagnosedReason?->value,
            'not_diagnosed_notes' => $notDiagnosedNotes,
        ]);
    }

    public function reopenProvisionalEmpties(int $orderId): array
    {
        $rows = ServiceOrderFemale::withoutGlobalScopes()
            ->where('service_order_id', $orderId)
            ->where('reproductive_status', FemaleReproductiveVerdict::EMPTY->value)
            ->whereNotNull('exposed_until')
            ->whereColumn('diagnosed_at', '<', 'exposed_until')
            ->get(['id', 'female_caravan_id']);

        if ($rows->isEmpty()) {
            return [];
        }

        ServiceOrderFemale::withoutGlobalScopes()
            ->whereIn('id', $rows->pluck('id'))
            ->update([
                'reproductive_status' => FemaleReproductiveVerdict::UNCHECKED->value,
                'diagnosed_at' => null,
            ]);

        return $rows->pluck('female_caravan_id')->map(fn ($id) => (int) $id)->all();
    }

    public function markExitedWhereUnchecked(int $femaleId): void
    {
        $orderIds = ServiceOrder::withoutGlobalScopes()
            ->whereIn('status', ServiceOrderStatus::openValues())
            ->pluck('id');

        ServiceOrderFemale::withoutGlobalScopes()
            ->whereIn('service_order_id', $orderIds)
            ->where('female_caravan_id', $femaleId)
            ->where('reproductive_status', FemaleReproductiveVerdict::UNCHECKED->value)
            ->update(['reproductive_status' => FemaleReproductiveVerdict::EXITED->value]);
    }

    public function reopenLostPregnancy(int $gestationId): void
    {
        $orderIds = ServiceOrder::withoutGlobalScopes()
            ->whereIn('status', ServiceOrderStatus::openValues())
            ->pluck('id');

        ServiceOrderFemale::withoutGlobalScopes()
            ->whereIn('service_order_id', $orderIds)
            ->where('gestation_id', $gestationId)
            ->where('reproductive_status', FemaleReproductiveVerdict::PREGNANT->value)
            ->update([
                'reproductive_status' => FemaleReproductiveVerdict::UNCHECKED->value,
                'diagnosed_at' => null,
            ]);
    }

    public function findNonPregnantFemalesInBatch(int $orderId, int $batchId, array $verdicts = []): array
    {
        $query = DB::table('service_order_females')
            ->join('caravans', 'caravans.id', '=', 'service_order_females.female_caravan_id')
            ->where('service_order_females.service_order_id', $orderId)
            ->where('caravans.batch_id', $batchId)
            ->whereNotIn('service_order_females.reproductive_status', [
                FemaleReproductiveVerdict::PREGNANT->value,
                FemaleReproductiveVerdict::EXITED->value,
            ]);

        if ($verdicts !== []) {
            $query->whereIn('service_order_females.reproductive_status', $verdicts);
        }

        return $query->pluck('service_order_females.female_caravan_id')->map(fn ($id) => (int) $id)->all();
    }

    public function completeActiveBulls(int $orderId, string $date): void
    {
        ServiceOrderMale::withoutGlobalScopes()
            ->where('service_order_id', $orderId)
            ->where('status', 'ACTIVE')
            ->update(['status' => 'COMPLETED', 'retired_at' => substr($date, 0, 10)]);
    }

    public function getDiagnosisDetail(int $orderId, int $companyId): array
    {
        $rounds = ServiceOrderDiagnosisRound::withoutGlobalScopes()
            ->where('service_order_id', $orderId)
            ->orderBy('round_date')
            ->get(['id', 'round_date']);

        $roundNumber = [];
        $roundDate = [];
        foreach ($rounds->values() as $index => $round) {
            $roundNumber[(int) $round->id] = $index + 1;
            $roundDate[(int) $round->id] = $round->round_date->format('Y-m-d');
        }

        // Full history in loading order: per female, the last row is the current verdict and the
        // one before it the previous; per round, a row of a female already diagnosed is a re-check.
        $history = ServiceOrderFemaleDiagnosis::withoutGlobalScopes()
            ->where('service_order_id', $orderId)
            ->orderBy('id')
            ->get(['id', 'diagnosis_round_id', 'female_caravan_id', 'verdict']);

        $byFemale = [];
        $roundStats = [];
        foreach ($history as $entry) {
            $femaleId = (int) $entry->female_caravan_id;
            $roundId = (int) $entry->diagnosis_round_id;
            $roundStats[$roundId] ??= ['diagnosed' => 0, 'pregnant' => 0, 'empty' => 0, 'not_diagnosed' => 0, 'rechecks' => 0];
            $roundStats[$roundId]['diagnosed']++;
            $key = match ($entry->verdict) {
                FemaleReproductiveVerdict::PREGNANT->value => 'pregnant',
                FemaleReproductiveVerdict::EMPTY->value => 'empty',
                default => 'not_diagnosed',
            };
            $roundStats[$roundId][$key]++;
            if (isset($byFemale[$femaleId])) {
                $roundStats[$roundId]['rechecks']++;
            }
            $byFemale[$femaleId][] = [
                'verdict' => (string) $entry->verdict,
                'round_number' => $roundNumber[$roundId] ?? null,
                'round_date' => $roundDate[$roundId] ?? null,
            ];
        }

        $roundsOut = [];
        foreach ($rounds as $round) {
            $id = (int) $round->id;
            $roundsOut[] = [
                'id' => $id,
                'number' => $roundNumber[$id],
                'round_date' => $roundDate[$id],
            ] + ($roundStats[$id] ?? ['diagnosed' => 0, 'pregnant' => 0, 'empty' => 0, 'not_diagnosed' => 0, 'rechecks' => 0]);
        }

        $rows = DB::table('service_order_females')
            ->join('caravans', 'caravans.id', '=', 'service_order_females.female_caravan_id')
            ->leftJoin('batches', 'batches.id', '=', 'caravans.batch_id')
            ->leftJoin('caravan_gestations', 'caravan_gestations.id', '=', 'service_order_females.gestation_id')
            ->where('service_order_females.service_order_id', $orderId)
            ->orderBy('caravans.identification')
            ->get([
                'service_order_females.female_caravan_id',
                'service_order_females.reproductive_status',
                'service_order_females.diagnosed_at',
                'service_order_females.exposed_from',
                'service_order_females.exposed_until',
                'service_order_females.exit_reason',
                'service_order_females.gestation_id',
                'service_order_females.not_diagnosed_reason',
                'service_order_females.not_diagnosed_notes',
                'service_order_females.assigned_male_caravan_id',
                'caravans.identification',
                'caravans.batch_id',
                'batches.name as batch_name',
                'caravan_gestations.gestation_stage',
                'caravan_gestations.gestation_months',
            ]);

        $confirmedSireByGestation = DB::table('gestation_sires')
            ->whereIn('gestation_id', $rows->pluck('gestation_id')->filter()->all())
            ->where('is_confirmed', true)
            ->pluck('sire_id', 'gestation_id');

        $females = [];
        foreach ($rows as $row) {
            $femaleId = (int) $row->female_caravan_id;
            $entries = $byFemale[$femaleId] ?? [];
            $confirmedSireId = $row->gestation_id !== null ? ($confirmedSireByGestation[$row->gestation_id] ?? null) : null;
            $previous = count($entries) >= 2 ? $entries[count($entries) - 2] : null;
            // A female reopened as pending keeps her last verdict only in the history.
            $current = (string) ($row->reproductive_status ?? FemaleReproductiveVerdict::UNCHECKED->value);
            if ($current === FemaleReproductiveVerdict::UNCHECKED->value && $entries !== []) {
                $previous = $entries[count($entries) - 1];
            }

            $females[] = [
                'female_caravan_id' => $femaleId,
                'identification' => (string) $row->identification,
                'current_batch_id' => $row->batch_id !== null ? (int) $row->batch_id : null,
                'current_batch_name' => $row->batch_name,
                'reproductive_status' => $current,
                'diagnosed_at' => $row->diagnosed_at !== null ? substr((string) $row->diagnosed_at, 0, 10) : null,
                'exposed_from' => $row->exposed_from !== null ? substr((string) $row->exposed_from, 0, 10) : null,
                'exposed_until' => $row->exposed_until !== null ? substr((string) $row->exposed_until, 0, 10) : null,
                'exit_reason' => $row->exit_reason,
                'gestation_id' => $row->gestation_id !== null ? (int) $row->gestation_id : null,
                'gestation_stage' => $row->gestation_stage,
                'gestation_months' => $row->gestation_months !== null ? (float) $row->gestation_months : null,
                'not_diagnosed_reason' => $row->not_diagnosed_reason,
                'not_diagnosed_notes' => $row->not_diagnosed_notes,
                'assigned_male_caravan_id' => $row->assigned_male_caravan_id !== null ? (int) $row->assigned_male_caravan_id : null,
                'confirmed_sire_id' => $confirmedSireId !== null ? (int) $confirmedSireId : null,
                'previous_diagnosis' => $previous,
                'diagnoses_count' => count($entries),
            ];
        }

        return ['rounds' => $roundsOut, 'females' => $females];
    }
}
