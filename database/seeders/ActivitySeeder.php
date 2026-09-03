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
                'is_final' => true,
            ],
            [
                'name' => 'Actividad Interna',
                'code' => 'INTERNAL',
            ],
        ];

        $activityIds = [];
        $companyId = \App\Models\Company::first()->id;

        foreach ($activitiesData as $data) {
            $activity = \App\Models\Activity::updateOrCreate(
                ['code' => $data['code']],
                ['name' => $data['name'], 'is_final' => $data['is_final'] ?? false]
            );
            $activityIds[$data['code']] = $activity->id;

            // Activar automáticamente para la empresa (Tenant por defecto)
            \App\Models\CompanyActivity::updateOrCreate(
                ['company_id' => $companyId, 'activity_id' => $activity->id],
                ['is_enabled' => true]
            );
        }

        // Relacionar los lotes creados en LivestockHierarchySeeder con sus actividades
        \App\Models\Batch::where('name', 'like', '%Invierno%')
            ->orWhere('name', 'like', '%Invernada%')
            ->update(['activity_id' => $activityIds['INVERNADA']]);

        \App\Models\Batch::where('name', 'like', '%Recría%')
            ->orWhere('name', 'like', '%Recria%')
            ->update(['activity_id' => $activityIds['RECRIA']]);

        \App\Models\Batch::where('name', 'like', '%Cría%')
            ->orWhere('name', 'like', '%Cria%')
            ->orWhere('name', 'like', '%CRIA%')
            ->update(['activity_id' => $activityIds['CRIA']]);
    }
}
