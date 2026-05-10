<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Activity;
use Illuminate\Database\Seeder;

class ActivitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $activitiesData = [
            [
                'name' => 'Cría',
                'code' => 'CRIA',
            ],
            [
                'name' => 'Recría',
                'code' => 'RECRIA',
            ],
            [
                'name' => 'Invernada',
                'code' => 'INVERNADA',
            ],
        ];

        $activityIds = [];

        foreach ($activitiesData as $data) {
            $activity = \App\Models\Activity::updateOrCreate(
                ['code' => $data['code']],
                ['name' => $data['name']]
            );
            $activityIds[$data['code']] = $activity->id;

            // Activar automáticamente para la empresa 1 (Tenant por defecto)
            \App\Models\CompanyActivity::updateOrCreate(
                ['company_id' => 1, 'activity_id' => $activity->id],
                ['is_enabled' => true]
            );
        }

        // Relacionar los lotes creados en LivestockHierarchySeeder con sus actividades
        \App\Models\Batch::where('name', 'like', '%Invierno%')
            ->update(['activity_id' => $activityIds['INVERNADA']]);

        \App\Models\Batch::where('name', 'like', '%Recría%')
            ->update(['activity_id' => $activityIds['RECRIA']]);
    }
}
