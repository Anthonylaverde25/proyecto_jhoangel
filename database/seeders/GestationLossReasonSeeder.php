<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GestationLossReason;
use App\Models\Company;
use Illuminate\Database\Seeder;

class GestationLossReasonSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $reasons = [
            [
                'code' => 'ABORTION',
                'name' => 'Aborto',
                'description' => 'Pérdida gestacional espontánea',
            ],
            [
                'code' => 'STILLBORN',
                'name' => 'Nacido muerto',
                'description' => 'Cría nacida sin vida',
            ],
            [
                'code' => 'PERINATAL_DEATH',
                'name' => 'Muerte perinatal',
                'description' => 'Muerte en las primeras 48hs',
            ],
            [
                'code' => 'REABSORPTION',
                'name' => 'Reabsorción embrionaria',
                'description' => 'Embrión reabsorbido',
            ],
            [
                'code' => 'OTHER',
                'name' => 'Otro',
                'description' => 'Motivo no especificado',
            ],
        ];

        $companies = Company::all();
        if ($companies->isEmpty()) {
            return;
        }

        foreach ($companies as $company) {
            foreach ($reasons as $reason) {
                GestationLossReason::withoutGlobalScopes()->updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'code' => $reason['code'],
                    ],
                    [
                        'name' => $reason['name'],
                        'description' => $reason['description'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
