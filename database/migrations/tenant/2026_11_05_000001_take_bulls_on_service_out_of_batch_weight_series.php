<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The weights of a batch now describe its herd: a bull of a running service is in the batch but
 * measures nothing. This cleans what was recorded before that rule for the services still running:
 * the points written since each bull arrived lose his head and his kilos (the step in the curve was
 * never a gain of the herd), a movement point left with nothing to say is removed, and the
 * aggregates stored on the batch are read again.
 *
 * Services already over keep their history: those bulls are gone and that curve shows real movements.
 * Irreversible by design: putting the bulls back would restore the false step, so down() does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stays = DB::table('service_order_males as m')
            ->join('service_orders as o', 'o.id', '=', 'm.service_order_id')
            ->join('caravans as c', 'c.id', '=', 'm.male_caravan_id')
            ->where('m.status', 'ACTIVE')
            ->where('o.status', 'APPROVED')
            ->whereColumn('c.batch_id', 'o.batch_id')
            ->get(['m.male_caravan_id as bull_id', 'o.batch_id', 'm.created_at as stay_created_at']);

        foreach ($stays->groupBy('batch_id') as $batchId => $batchStays) {
            DB::transaction(function () use ($batchId, $batchStays): void {
                foreach ($batchStays as $stay) {
                    $this->takeBullOutOfSeries((int) $batchId, (int) $stay->bull_id, $this->arrivalOf($stay, (int) $batchId));
                }

                $this->removeMovementPointsWithNothingToSay((int) $batchId);
                $this->refreshBatchAggregates((int) $batchId, $batchStays->pluck('bull_id')->map(fn ($id) => (int) $id));
            });
        }
    }

    public function down(): void
    {
    }

    /** When the system first held him here: a bull that already lived in the batch has no movement. */
    private function arrivalOf(object $stay, int $batchId): ?string
    {
        return DB::table('caravan_movements')
            ->where('caravan_id', $stay->bull_id)
            ->where('to_batch_id', $batchId)
            ->orderByDesc('id')
            ->value('created_at') ?? $stay->stay_created_at;
    }

    private function takeBullOutOfSeries(int $batchId, int $bullId, ?string $arrival): void
    {
        if ($arrival === null) {
            return;
        }

        $points = DB::table('batch_weights')->where('batch_id', $batchId)->where('created_at', '>=', $arrival)->get();

        foreach ($points as $point) {
            // His weight as the point saw it: the newest one on record when the point was written.
            $weight = DB::table('caravan_weights')
                ->where('caravan_id', $bullId)
                ->where('created_at', '<=', $point->created_at)
                ->orderByDesc('weighing_date')
                ->orderByDesc('id')
                ->value('weight');

            $weighed = max(0, (int) $point->weighed_count - ($weight !== null ? 1 : 0));
            $total = max(0.0, (float) $point->total_weight - (float) ($weight ?? 0));

            DB::table('batch_weights')->where('id', $point->id)->update([
                'caravans_count' => max(0, (int) $point->caravans_count - 1),
                'weighed_count' => $weighed,
                'total_weight' => $total,
                'weight' => $weighed > 0 ? round($total / $weighed, 2) : null,
            ]);
        }
    }

    private function removeMovementPointsWithNothingToSay(int $batchId): void
    {
        $previous = null;

        foreach (DB::table('batch_weights')->where('batch_id', $batchId)->orderBy('weighing_date')->orderBy('id')->get() as $point) {
            $repeats = $previous !== null
                && in_array($point->type, ['MOVEMENT_IN', 'MOVEMENT_OUT'], true)
                && (int) $point->caravans_count === (int) $previous->caravans_count
                && (int) $point->weighed_count === (int) $previous->weighed_count
                && abs((float) $point->total_weight - (float) $previous->total_weight) < 0.01;

            if ($repeats) {
                DB::table('batch_weights')->where('id', $point->id)->delete();
                continue;
            }

            $previous = $point;
        }
    }

    /** @param Collection<int, int> $bullIds */
    private function refreshBatchAggregates(int $batchId, Collection $bullIds): void
    {
        $weights = DB::table('caravans')
            ->join('caravan_weights', 'caravan_weights.caravan_id', '=', 'caravans.id')
            ->where('caravans.batch_id', $batchId)
            ->where('caravan_weights.current', true)
            ->whereNotIn('caravans.id', $bullIds->all())
            ->selectRaw('COUNT(*) as weighed, COALESCE(SUM(caravan_weights.weight), 0) as total, MIN(caravan_weights.weight) as lightest, MAX(caravan_weights.weight) as heaviest')
            ->first();
        $weighed = (int) $weights->weighed;

        DB::table('batches')->where('id', $batchId)->update([
            'weighed_count' => $weighed,
            'total_weight' => (float) $weights->total,
            'current_weight' => $weighed > 0 ? (float) $weights->total / $weighed : null,
            'min_weight' => $weights->lightest,
            'max_weight' => $weights->heaviest,
        ]);
    }
};
