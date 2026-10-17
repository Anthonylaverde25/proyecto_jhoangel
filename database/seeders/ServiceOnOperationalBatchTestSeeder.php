<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Enums\BatchWeightCause;
use App\Core\Services\BatchWeightService;
use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\AnimalSubcategory;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\BullHealthEvaluation;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\Company;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Scenario for the service run on an operational Cría batch (no service batch is ever created).
 * Tags carry the SRV- prefix and batches the "Srv" word, so they are recognisable at a glance.
 *
 *  - "Srv Rodeo en Servicio" (Cría, operational): SRV-VC-01..40 cows under a running natural
 *    service SO-YYYY-9xx with bulls SRV-TR-01/02. SRV-VC-41 entered the batch during the service
 *    (joined_after_start) and SRV-VC-40 left it to "Srv Rodeo Vecino" (TRANSFERRED_OUT). The same
 *    batch keeps the closed service of the previous campaign, for the history tab.
 *  - "Srv Rodeo Tacto" (Cría, operational): SRV-TC-01..10 under a running service (bull SRV-TR-05
 *    still in the batch) with a first round scanned on 2026-09-10: TC-01..03 pregnant (stage only,
 *    months only, nothing declared), TC-04 empty while still with the bull (provisional: re-check),
 *    TC-05 empty and moved to "Srv Rodeo Vacías" that day (final). TC-06..10 unchecked. "Cerrar
 *    orden" asks for the missing diagnosis and then what happens with the bull.
 *  - "Srv Rodeo Espera Tacto" (Cría, operational): SRV-ET-01..12 under SO-YYYY-903, bulls withdrawn
 *    on 2026-08-30 (BULLS_WITHDRAWN, suggested diagnosis 2026-10-14). Round 1 (2026-08-15, bulls
 *    in): ET-05 empty, reopened as pending at the withdrawal; ET-03 empty and moved to "Srv Rodeo
 *    Vacías". Round 2 (2026-10-10): ET-01/02 pregnant without stage, ET-03 pregnant (open incident:
 *    pregnant outside the service batch), ET-04 empty. ET-06 died before any verdict (EXITED).
 *    ET-05 and ET-07..12 are pending.
 *  - "Srv Rodeo Listo" (Cría, operational): SRV-LS-01..15 cows without a service, and two free APT
 *    bulls SRV-TR-03/04 in "Srv Torada Descanso", to try "Iniciar servicio" end to end.
 *  - Breeding eligibility (the veterinary module is optional): "Srv Rodeo Aptitud" (SRV-AP-01..05,
 *    all eligible) and "Srv Rodeo Descarte" (SRV-DS-01..04 eligible, SRV-DS-05 excluded as CUT).
 *    Free bulls in "Srv Torada Descanso": SRV-TR-06 never evaluated, SRV-TR-07 APT but excluded by
 *    the owner (RESTING), SRV-TR-08 UNFIT, SRV-TR-09 APT.
 *
 * Idempotent: every run deletes its own orders and puts each animal back.
 *     php artisan tenants:seed --class=ServiceOnOperationalBatchTestSeeder
 */
class ServiceOnOperationalBatchTestSeeder extends Seeder
{
    private const CURRENT_START = '2026-10-01';
    private const PREVIOUS_START = '2025-10-01';
    private const PREVIOUS_END = '2025-12-30';
    private const PREVIOUS_DIAGNOSIS = '2026-02-15';

    public function run(): void
    {
        $company = Company::first();
        if (!$company) {
            $this->command?->warn('ServiceOnOperationalBatchTestSeeder: no hay compañía cargada. Se omite.');

            return;
        }

        $companyId = (int) $company->id;
        $userId = User::query()->value('id');
        $year = substr(self::CURRENT_START, 0, 4);

        $rodeo = $this->batch($companyId, 'Srv Rodeo en Servicio');
        $neighbour = $this->batch($companyId, 'Srv Rodeo Vecino');
        $tactoRodeo = $this->batch($companyId, 'Srv Rodeo Tacto');
        $waitingRodeo = $this->batch($companyId, 'Srv Rodeo Espera Tacto');
        $emptiesRodeo = $this->batch($companyId, 'Srv Rodeo Vacías');
        $readyRodeo = $this->batch($companyId, 'Srv Rodeo Listo');
        $bullPaddock = $this->batch($companyId, 'Srv Torada Descanso');
        $aptitudeRodeo = $this->batch($companyId, 'Srv Rodeo Aptitud');
        $cullRodeo = $this->batch($companyId, 'Srv Rodeo Descarte');

        $batchIds = [
            $rodeo->id, $neighbour->id, $tactoRodeo->id, $waitingRodeo->id, $emptiesRodeo->id,
            $readyRodeo->id, $bullPaddock->id, $aptitudeRodeo->id, $cullRodeo->id,
        ];
        $this->reset($batchIds);

        $vaca = $this->category('VACA');
        $rodeoGeneral = AnimalSubcategory::withoutGlobalScopes()->where('code', 'RODEO_GENERAL')->value('id');
        $toro = $this->category('TORO');

        // --- Running service with entries and exits --------------------------------------------
        $cows = [];
        for ($i = 1; $i <= 40; $i++) {
            $cows[] = $this->animal($companyId, sprintf('SRV-VC-%02d', $i), $rodeo, 'H', $vaca, $rodeoGeneral, 430 + $i);
        }
        $newcomer = $this->animal($companyId, 'SRV-VC-41', $rodeo, 'H', $vaca, $rodeoGeneral, 445);
        $bulls = [
            $this->bull($companyId, 'SRV-TR-01', $rodeo, $toro),
            $this->bull($companyId, 'SRV-TR-02', $rodeo, $toro),
        ];

        // Previous campaign, closed by its diagnosis: 33 pregnant out of 40 (82.5 %).
        $previous = $this->order($companyId, $userId, $rodeo, "SO-2025-901", 'SUCCESS', self::PREVIOUS_START, self::PREVIOUS_END, 82.5);
        ServiceOrder::withoutGlobalScopes()->whereKey($previous)->update(['diagnosis_completed_at' => self::PREVIOUS_DIAGNOSIS]);
        $previousRound = $this->round($companyId, $previous, self::PREVIOUS_DIAGNOSIS);
        foreach ($cows as $index => $cowId) {
            $this->exposure($companyId, $previous, $cowId, self::PREVIOUS_START, self::PREVIOUS_END, 'BULLS_WITHDRAWN');
            $this->verdict($companyId, $previous, $previousRound, $cowId, $index < 33 ? 'PREGNANT' : 'EMPTY', self::PREVIOUS_DIAGNOSIS, $userId);
        }
        foreach ($bulls as $bullId) {
            $this->serviceBull($companyId, $previous, $bullId, self::PREVIOUS_START, 'COMPLETED', self::PREVIOUS_END);
        }

        $current = $this->order($companyId, $userId, $rodeo, "SO-{$year}-901", 'APPROVED', self::CURRENT_START, null, null);
        foreach ($cows as $cowId) {
            $this->exposure($companyId, $current, $cowId, self::CURRENT_START);
        }
        foreach ($bulls as $bullId) {
            $this->serviceBull($companyId, $current, $bullId, self::CURRENT_START, 'ACTIVE');
        }

        // SRV-VC-41 entered with the service already running.
        $this->exposure($companyId, $current, $newcomer, '2026-10-20', null, null, true);
        $this->movement($companyId, $newcomer, $neighbour->id, $rodeo->id, '2026-10-20', 'Ingreso al rodeo con el servicio en curso');

        // SRV-VC-40 left the batch: her exposure ended that day.
        $leaver = $cows[39];
        DB::table('service_order_females')
            ->where('service_order_id', $current)
            ->where('female_caravan_id', $leaver)
            ->update(['exposed_until' => '2026-10-25', 'exit_reason' => 'TRANSFERRED_OUT']);
        Caravan::withoutGlobalScopes()->whereKey($leaver)->update(['batch_id' => $neighbour->id]);
        $this->movement($companyId, $leaver, $rodeo->id, $neighbour->id, '2026-10-25', 'Salida del rodeo durante el servicio');

        // --- Running service with a first round scanned while the bull is still in ---------------
        $tactoCows = [];
        for ($i = 1; $i <= 10; $i++) {
            $tactoCows[] = $this->animal($companyId, sprintf('SRV-TC-%02d', $i), $tactoRodeo, 'H', $vaca, $rodeoGeneral, 440 + $i);
        }
        $tactoBull = $this->bull($companyId, 'SRV-TR-05', $tactoRodeo, $toro);
        $tactoOrder = $this->order($companyId, $userId, $tactoRodeo, "SO-{$year}-902", 'APPROVED', '2026-07-01', '2026-09-30', null);
        $this->serviceBull($companyId, $tactoOrder, $tactoBull, '2026-07-01', 'ACTIVE');
        foreach ($tactoCows as $cowId) {
            $this->exposure($companyId, $tactoOrder, $cowId, '2026-07-01');
        }

        $scanDay = '2026-09-10';
        $tactoRound = $this->round($companyId, $tactoOrder, $scanDay);
        // Stage only, months only (stage derived), nothing declared: all three are valid.
        $declared = [['head', null], ['body', 2.0], [null, null]];
        foreach ($declared as $index => [$stage, $months]) {
            $gestationId = $this->gestation($tactoCows[$index], $tactoOrder, $scanDay, $stage, $months);
            $this->verdict($companyId, $tactoOrder, $tactoRound, $tactoCows[$index], 'PREGNANT', $scanDay, $userId, $gestationId, $stage, $months);
        }
        // TC-04: empty while still sharing the paddock with the bull. Provisional.
        $this->verdict($companyId, $tactoOrder, $tactoRound, $tactoCows[3], 'EMPTY', $scanDay, $userId);
        // TC-05: empty and out of the batch the same day. Final.
        $this->verdict($companyId, $tactoOrder, $tactoRound, $tactoCows[4], 'EMPTY', $scanDay, $userId);
        $this->leave($companyId, $tactoOrder, $tactoCows[4], $tactoRodeo->id, $emptiesRodeo->id, $scanDay, 'TRANSFERRED_OUT', 'Vacía en ecografía: sale al lote de vacías');

        // --- Bulls withdrawn, waiting for the diagnosis, two rounds loaded ----------------------
        $waitingCows = [];
        for ($i = 1; $i <= 12; $i++) {
            $waitingCows[] = $this->animal($companyId, sprintf('SRV-ET-%02d', $i), $waitingRodeo, 'H', $vaca, $rodeoGeneral, 450 + $i);
        }
        $waitingBull = $this->bull($companyId, 'SRV-TR-10', $bullPaddock, $toro);
        $withdrawal = '2026-08-30';
        $waitingOrder = $this->order($companyId, $userId, $waitingRodeo, "SO-{$year}-903", 'BULLS_WITHDRAWN', '2026-06-01', '2026-08-30', null);
        ServiceOrder::withoutGlobalScopes()->whereKey($waitingOrder)->update(['actual_end_date' => $withdrawal]);
        $this->serviceBull($companyId, $waitingOrder, $waitingBull, '2026-06-01', 'COMPLETED', $withdrawal);
        $this->movement($companyId, $waitingBull, $waitingRodeo->id, $bullPaddock->id, $withdrawal, "Retiro total de torada por finalización de orden SO-{$year}-903");
        foreach ($waitingCows as $cowId) {
            $this->exposure($companyId, $waitingOrder, $cowId, '2026-06-01', $withdrawal, 'BULLS_WITHDRAWN');
        }

        // Round 1, with the bulls still in: ET-03 empty and moved out (final), ET-05 empty and kept
        // with the bulls (reopened as pending when they were withdrawn).
        $earlyRound = $this->round($companyId, $waitingOrder, '2026-08-15');
        $this->verdict($companyId, $waitingOrder, $earlyRound, $waitingCows[2], 'EMPTY', '2026-08-15', $userId);
        $this->leave($companyId, $waitingOrder, $waitingCows[2], $waitingRodeo->id, $emptiesRodeo->id, '2026-08-15', 'TRANSFERRED_OUT', 'Vacía en ecografía temprana: sale al lote de vacías');
        $this->verdict($companyId, $waitingOrder, $earlyRound, $waitingCows[4], 'EMPTY', '2026-08-15', $userId);
        DB::table('service_order_females')
            ->where('service_order_id', $waitingOrder)
            ->where('female_caravan_id', $waitingCows[4])
            ->update(['reproductive_status' => 'UNCHECKED', 'diagnosed_at' => null]);

        // ET-06 died before any verdict.
        $this->leave($companyId, $waitingOrder, $waitingCows[5], $waitingRodeo->id, null, '2026-08-20', 'DEATH', 'Muerte');
        DB::table('service_order_females')
            ->where('service_order_id', $waitingOrder)
            ->where('female_caravan_id', $waitingCows[5])
            ->update(['reproductive_status' => 'EXITED']);

        // Round 2, after the suggested wait: ET-01/02 pregnant with nothing else declared, ET-03
        // pregnant in the empties batch (open incident), ET-04 empty.
        $secondRound = $this->round($companyId, $waitingOrder, '2026-10-10');
        foreach ([0, 1, 2] as $index) {
            $gestationId = $this->gestation($waitingCows[$index], $waitingOrder, '2026-10-10', null, null);
            $this->verdict($companyId, $waitingOrder, $secondRound, $waitingCows[$index], 'PREGNANT', '2026-10-10', $userId, $gestationId);
        }
        $this->verdict($companyId, $waitingOrder, $secondRound, $waitingCows[3], 'EMPTY', '2026-10-10', $userId);
        DB::table('service_order_incidents')->insert([
            'company_id' => $companyId,
            'service_order_id' => $waitingOrder,
            'female_caravan_id' => $waitingCows[2],
            'type' => 'PREGNANT_OUTSIDE_SERVICE_BATCH',
            'detail' => "SRV-ET-03 se diagnosticó preñada el 10/10/2026 y está en el lote 'Srv Rodeo Vacías', fuera del lote de servicio, adonde se la movió tras un diagnóstico de vacía.",
            'metadata' => json_encode(['diagnosis_date' => '2026-10-10', 'current_batch_id' => $emptiesRodeo->id, 'service_batch_id' => $waitingRodeo->id]),
            'status' => 'OPEN',
            'raised_by_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // --- Batch ready to start a service, with free APT bulls ---------------------------------
        for ($i = 1; $i <= 15; $i++) {
            $this->animal($companyId, sprintf('SRV-LS-%02d', $i), $readyRodeo, 'H', $vaca, $rodeoGeneral, 420 + $i);
        }
        $this->bull($companyId, 'SRV-TR-03', $bullPaddock, $toro);
        $this->bull($companyId, 'SRV-TR-04', $bullPaddock, $toro);

        // --- Breeding eligibility without the veterinary module ----------------------------------
        for ($i = 1; $i <= 5; $i++) {
            $this->animal($companyId, sprintf('SRV-AP-%02d', $i), $aptitudeRodeo, 'H', $vaca, $rodeoGeneral, 425 + $i);
            $this->animal($companyId, sprintf('SRV-DS-%02d', $i), $cullRodeo, 'H', $vaca, $rodeoGeneral, 410 + $i);
        }
        $this->exclude($this->idOf($companyId, 'SRV-DS-05'), 'CUT_DENTITION', 'Boqueo: vaca CUT, va a engorde.');

        $unevaluated = $this->animal($companyId, 'SRV-TR-06', $bullPaddock, 'M', $toro, null, 760);
        BullHealthEvaluation::withoutGlobalScopes()->where('caravan_id', $unevaluated)->delete();
        $this->exclude($this->bull($companyId, 'SRV-TR-07', $bullPaddock, $toro), 'RESTING', 'Descanso esta temporada.');
        $this->bull($companyId, 'SRV-TR-08', $bullPaddock, $toro, 'UNFIT');
        $this->bull($companyId, 'SRV-TR-09', $bullPaddock, $toro);

        $weights = app(BatchWeightService::class);
        foreach ($batchIds as $batchId) {
            $weights->recalculateBatchWeight((int) $batchId, BatchWeightCause::MOVEMENT_IN);
        }

        $this->command?->info('ServiceOnOperationalBatchTestSeeder: rodeos Srv listos (servicio en curso, tacto con toros en el lote, orden esperando tacto con dos rondas y rodeo listo para servicio).');
    }

    /**
     * @param int[] $batchIds
     */
    private function reset(array $batchIds): void
    {
        $orderIds = ServiceOrder::withoutGlobalScopes()->whereIn('batch_id', $batchIds)->pluck('id');
        $caravanIds = Caravan::withoutGlobalScopes()->where('identification', 'like', 'SRV-%')->pluck('id');

        DB::table('service_order_incidents')->whereIn('service_order_id', $orderIds)->delete();
        DB::table('service_order_female_diagnoses')->whereIn('service_order_id', $orderIds)->delete();
        DB::table('service_order_diagnosis_rounds')->whereIn('service_order_id', $orderIds)->delete();
        DB::table('service_order_females')->whereIn('service_order_id', $orderIds)->update(['gestation_id' => null]);
        ServiceOrder::withoutGlobalScopes()->whereIn('id', $orderIds)->update(['parent_service_order_id' => null]);
        CaravanGestation::whereIn('caravan_id', $caravanIds)->delete();
        DB::table('service_order_histories')->whereIn('service_order_id', $orderIds)->delete();
        DB::table('service_order_females')->whereIn('service_order_id', $orderIds)->delete();
        DB::table('service_order_males')->whereIn('service_order_id', $orderIds)->delete();
        ServiceOrder::withoutGlobalScopes()->whereIn('id', $orderIds)->delete();
        DB::table('caravan_movements')->whereIn('caravan_id', $caravanIds)->delete();
        DB::table('caravan_breeding_eligibility_changes')->whereIn('caravan_id', $caravanIds)->delete();
        DB::table('batch_weights')->whereIn('batch_id', $batchIds)->delete();
    }

    private function batch(int $companyId, string $name): Batch
    {
        return Batch::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'name' => $name],
            [
                'activity_id' => Activity::withoutGlobalScopes()->where('code', 'CRIA')->value('id'),
                'batch_type_id' => BatchType::withoutGlobalScopes()->where('code', 'OPERATIONAL')->value('id'),
                'is_confined' => false,
                'is_active' => true,
                'is_system' => false,
                'observaciones' => 'Escenario de prueba de servicio sobre lote operacional.',
            ]
        );
    }

    private function category(string $code): ?int
    {
        $id = AnimalCategory::withoutGlobalScopes()->where('code', $code)->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function animal(int $companyId, string $tag, Batch $batch, string $sex, ?int $categoryId, mixed $subcategoryId, float $weight): int
    {
        $caravan = Caravan::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'identification' => $tag],
            [
                'batch_id' => $batch->id,
                'sex' => $sex,
                'teeth' => 8,
                'category_id' => $categoryId,
                'subcategory_id' => $subcategoryId,
                'entry_weight' => $weight,
                'entry_date' => '2025-03-01',
                // Explicit: seeders run without model events, and a rerun must undo an exclusion.
                'is_breeding_eligible' => true,
                'breeding_exclusion_reason' => null,
            ]
        );

        return (int) $caravan->id;
    }

    private function idOf(int $companyId, string $tag): int
    {
        return (int) Caravan::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('identification', $tag)
            ->value('id');
    }

    /**
     * The owner leaves the animal out of breeding, with the trace the endpoint would write.
     */
    private function exclude(int $caravanId, string $reason, string $note): void
    {
        $caravan = Caravan::withoutGlobalScopes()->findOrFail($caravanId);
        $caravan->update(['is_breeding_eligible' => false, 'breeding_exclusion_reason' => $reason]);

        DB::table('caravan_breeding_eligibility_changes')->insert([
            'company_id' => $caravan->company_id,
            'caravan_id' => $caravanId,
            'is_breeding_eligible' => false,
            'reason' => $reason,
            'note' => $note,
            'changed_by_user_id' => User::query()->value('id'),
            'changed_at' => now(),
        ]);
    }

    private function bull(int $companyId, string $tag, Batch $batch, ?int $categoryId, string $status = 'APT'): int
    {
        $bullId = $this->animal($companyId, $tag, $batch, 'M', $categoryId, null, 780);

        BullHealthEvaluation::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'caravan_id' => $bullId],
            [
                'last_evaluation_date' => now()->subDays(20)->toDateString(),
                'aplomo_notes' => 'Aplomos correctos verificados en manga.',
                'scrotal_circumference_cm' => 37.0,
                'body_condition_score' => 3.5,
                'libido' => 'ALTA',
                'status' => $status,
                'observations' => $status === 'APT'
                    ? 'Escenario de prueba: toro apto para servicio.'
                    : 'Escenario de prueba: toro con rechazo sanitario.',
            ]
        );

        return $bullId;
    }

    private function order(int $companyId, mixed $userId, Batch $batch, string $code, string $status, string $start, ?string $end, ?float $pregnancyRate): int
    {
        $order = ServiceOrder::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'batch_id' => $batch->id,
            'code' => $code,
            'status' => $status,
            'service_type' => 'multi',
            'is_controlled_service' => false,
            'target_bull_ratio' => 3.0,
            'planned_start_date' => $start,
            'actual_start_date' => $start,
            'planned_end_date' => $end,
            'actual_end_date' => in_array($status, ['SUCCESS', 'BULLS_WITHDRAWN'], true) ? $end : null,
            'final_pregnancy_rate' => $pregnancyRate,
            'requested_by_user_id' => $userId,
            'approved_by_user_id' => $userId,
            'approved_at' => $start,
            'executed_at' => $start,
            'observations' => 'Escenario de prueba de servicio sobre lote operacional.',
        ]);

        return (int) $order->id;
    }

    private function exposure(
        int $companyId,
        int $orderId,
        int $cowId,
        string $from,
        ?string $until = null,
        ?string $exitReason = null,
        bool $joinedAfterStart = false,
        string $reproductiveStatus = 'UNCHECKED'
    ): void {
        DB::table('service_order_females')->insert([
            'company_id' => $companyId,
            'service_order_id' => $orderId,
            'female_caravan_id' => $cowId,
            'reproductive_status' => $reproductiveStatus,
            'exposed_from' => $from,
            'exposed_until' => $until,
            'exit_reason' => $exitReason,
            'joined_after_start' => $joinedAfterStart,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function serviceBull(int $companyId, int $orderId, int $bullId, string $joinedAt, string $status, ?string $retiredAt = null): void
    {
        DB::table('service_order_males')->insert([
            'company_id' => $companyId,
            'service_order_id' => $orderId,
            'male_caravan_id' => $bullId,
            'joined_at' => $joinedAt,
            'sanitary_status_at_entry' => 'APT',
            'snapshot_scrotal_circumference' => 37.0,
            'service_capacity' => 'HIGH',
            'status' => $status,
            'retired_at' => $retiredAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function round(int $companyId, int $orderId, string $date): int
    {
        return (int) DB::table('service_order_diagnosis_rounds')->insertGetId([
            'company_id' => $companyId,
            'service_order_id' => $orderId,
            'round_date' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A verdict kept in its round and made the current one, as the diagnosis use case does.
     */
    private function verdict(
        int $companyId,
        int $orderId,
        int $roundId,
        int $cowId,
        string $verdict,
        string $date,
        mixed $userId,
        ?int $gestationId = null,
        ?string $stage = null,
        ?float $months = null
    ): void {
        $femaleRowId = (int) DB::table('service_order_females')
            ->where('service_order_id', $orderId)
            ->where('female_caravan_id', $cowId)
            ->value('id');

        DB::table('service_order_female_diagnoses')->insert([
            'company_id' => $companyId,
            'service_order_id' => $orderId,
            'diagnosis_round_id' => $roundId,
            'service_order_female_id' => $femaleRowId,
            'female_caravan_id' => $cowId,
            'verdict' => $verdict,
            'gestation_stage' => $stage,
            'gestation_months' => $months,
            'gestation_id' => $gestationId,
            'diagnosed_by_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('service_order_females')->where('id', $femaleRowId)->update([
            'reproductive_status' => $verdict,
            'diagnosed_at' => $date,
            'diagnosed_by_user_id' => $userId,
            'gestation_id' => $gestationId,
        ]);
    }

    private function gestation(int $cowId, int $orderId, string $date, ?string $stage, ?float $months): int
    {
        $gestation = CaravanGestation::create([
            'caravan_id' => $cowId,
            'start_date' => $date,
            'is_current' => true,
            'gestation_stage' => $stage,
            'gestation_months' => $months,
            'service_order_id' => $orderId,
            'notes' => 'Diagnóstico de prueba (escenario de servicio).',
        ]);

        return (int) $gestation->id;
    }

    /**
     * The female leaves the serviced batch (or dies): her exposure ends that day.
     */
    private function leave(int $companyId, int $orderId, int $cowId, int $fromBatchId, ?int $toBatchId, string $date, string $exitReason, string $observations): void
    {
        DB::table('service_order_females')
            ->where('service_order_id', $orderId)
            ->where('female_caravan_id', $cowId)
            ->update(['exposed_until' => $date, 'exit_reason' => $exitReason]);

        if ($toBatchId !== null) {
            Caravan::withoutGlobalScopes()->whereKey($cowId)->update(['batch_id' => $toBatchId]);
            $this->movement($companyId, $cowId, $fromBatchId, $toBatchId, $date, $observations);
        }
    }

    /**
     * Written with the query builder on purpose: the scenario already states the exposure the
     * movement implies, so the exposure observer must not apply it a second time.
     */
    private function movement(int $companyId, int $caravanId, int $fromBatchId, int $toBatchId, string $date, string $observations): void
    {
        DB::table('caravan_movements')->insert([
            'company_id' => $companyId,
            'caravan_id' => $caravanId,
            'renspa' => 'NO_DEFINIDO',
            'type' => 'TRANSFER',
            'movement_date' => $date,
            'from_batch_id' => $fromBatchId,
            'to_batch_id' => $toBatchId,
            'observations' => $observations,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
