<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\DeathCause;
use Illuminate\Database\Seeder;

/**
 * The causes of death every company starts with. Idempotent: creates only what is missing and
 * never touches a cause the company already edited.
 */
class DeathCauseSeeder extends Seeder
{
    private const CAUSES = [
        ['code' => 'DIARRHEA', 'name' => 'Diarrea', 'description' => 'Diarrea neonatal o de recría.'],
        ['code' => 'PNEUMONIA', 'name' => 'Neumonía', 'description' => 'Enfermedad respiratoria.'],
        ['code' => 'BLOAT', 'name' => 'Empaste', 'description' => 'Meteorismo espumoso o gaseoso.'],
        ['code' => 'POISONING', 'name' => 'Intoxicación', 'description' => 'Plantas tóxicas, nitratos u otros tóxicos.'],
        ['code' => 'WEATHER', 'name' => 'Clima (frío, calor, inundación)', 'description' => 'Muerte por un evento climático.'],
        ['code' => 'PREDATOR', 'name' => 'Predador', 'description' => 'Ataque de un predador.'],
        ['code' => 'ACCIDENT', 'name' => 'Accidente', 'description' => 'Golpe, empantanamiento, alambrado u otro accidente.'],
        ['code' => 'CALVING_COMPLICATION', 'name' => 'Complicación de parto', 'description' => 'Distocia o complicación posparto.'],
        ['code' => 'UNKNOWN', 'name' => 'Desconocida', 'description' => 'Se encontró muerto sin causa aparente.'],
        ['code' => 'OTHER', 'name' => 'Otra', 'description' => 'Causa no listada: detallala en la observación.'],
    ];

    public function run(): void
    {
        foreach (Company::all() as $company) {
            foreach (self::CAUSES as $order => $cause) {
                DeathCause::withoutGlobalScopes()->firstOrCreate(
                    ['company_id' => $company->id, 'code' => $cause['code']],
                    [...$cause, 'is_active' => true, 'sort_order' => $order]
                );
            }
        }
    }
}
