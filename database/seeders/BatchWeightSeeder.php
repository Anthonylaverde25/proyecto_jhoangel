<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\BatchWeight;
use App\Models\Activity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class BatchWeightSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $batches = Batch::all();
        $activities = Activity::all();

        if ($batches->isEmpty() || $activities->isEmpty()) {
            return;
        }

        foreach ($batches as $batch) {
            // Generar un peso inicial hace 3 meses
            $currentDate = Carbon::now()->subMonths(3);
            $currentWeight = 150.0;

            // Registro Inicial
            BatchWeight::create([
                'batch_id' => $batch->id,
                'activity_id' => $activities->where('code', 'CRIA')->first()?->id ?? $activities->first()->id,
                'weight' => $currentWeight,
                'type' => 'INITIAL',
                'weighing_date' => $currentDate->toDateString(),
            ]);

            // Generar 3-5 registros adicionales simulando crecimiento
            $numRecords = rand(3, 6);
            for ($i = 0; $i < $numRecords; $i++) {
                $currentDate = $currentDate->addDays(rand(15, 25));
                $currentWeight += rand(15, 30); // Ganancia de peso simulada
                
                $type = ($i === $numRecords - 1) ? 'TRANSFER' : 'CONTROL';
                
                // Intentar variar la actividad para simular progreso
                $activity = $activities->random();

                BatchWeight::create([
                    'batch_id' => $batch->id,
                    'activity_id' => $activity->id,
                    'weight' => $currentWeight,
                    'type' => $type,
                    'weighing_date' => $currentDate->isFuture() ? Carbon::now()->toDateString() : $currentDate->toDateString(),
                ]);

                // Sync the batch current_weight with the latest simulated weight
                $batch->update(['current_weight' => $currentWeight]);

                if ($currentDate->isFuture()) break;
            }
        }
    }
}
