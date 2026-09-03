<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AnimalCategory;
use App\Models\BullHealthEvaluation;
use App\Models\BullLabSample;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\Pathogen;
use App\Models\User;
use App\Models\VeterinaryDiagnosis;
use Illuminate\Database\Seeder;

class BullHealthSeeder extends Seeder
{
    /**
     * Seed 30 bulls with longitudinal physical evaluations and multiple laboratory samples
     * (preputial scrapes & blood serology) across 12 months.
     * Grounded in Carrillo (1988) "Manejo de un Rodeo de Cría".
     */
    public function run(): void
    {
        $company = Company::first();
        if (!$company) {
            $company = Company::create([
                'name' => 'Establecimiento Ganadero Demo',
                'renspa' => '01.023.0.45678/01',
            ]);
        }
        $companyId = $company->id;

        // Ensure TORO category exists
        $toroCategory = AnimalCategory::where('code', 'TORO')->first();
        if (!$toroCategory) {
            $toroCategory = AnimalCategory::create([
                'code' => 'TORO',
                'name' => 'Toro Reproductor',
                'sex' => 'M',
                'is_reproductive' => true,
            ]);
        }

        // Ensure Veterinarian User exists
        $vetUser = User::where('email', 'veterinario@ganadero.com')->first();
        if (!$vetUser) {
            $vetUser = User::first() ?? User::create([
                'name' => 'Dr. Fernando Aranguren (M.V.)',
                'email' => 'veterinario@ganadero.com',
                'password' => bcrypt('secret123'),
            ]);
        }

        // Retrieve pathogen map
        $pathogens = Pathogen::all()->keyBy('code');

        // ==========================================
        // 15 TOROS APTOS (TR-001 a TR-015)
        // ==========================================
        $aptBullsConfig = [
            ['tag' => 'TR-001', 'ce' => 37.5, 'cc' => 3.5, 'libido' => 'ALTA', 'aplomo' => 'Aplomos correctos. Excelente paralelismo de miembros posteriores y ángulo de garrón ideal (Carrillo p. 167).'],
            ['tag' => 'TR-002', 'ce' => 36.0, 'cc' => 3.5, 'libido' => 'MUY_ALTA', 'aplomo' => 'Miembros fuertes, pezuñas simétricas y pigmentadas. Desplazamiento ágil en manga.'],
            ['tag' => 'TR-003', 'ce' => 35.5, 'cc' => 3.0, 'libido' => 'ALTA', 'aplomo' => 'Buen arqueo de garrón, sin desviaciones valgas ni varas. Apoyo firme y uniforme.'],
            ['tag' => 'TR-004', 'ce' => 38.0, 'cc' => 3.5, 'libido' => 'ALTA', 'aplomo' => 'Excelentes aplomos de miembros anteriores y posteriores. Pisada recta.'],
            ['tag' => 'TR-005', 'ce' => 34.5, 'cc' => 3.0, 'libido' => 'MEDIA', 'aplomo' => 'Aplomos normales. Cuartillas elásticas con ángulo de 45-50 grados adecuado para monte.'],
            ['tag' => 'TR-006', 'ce' => 36.5, 'cc' => 3.5, 'libido' => 'ALTA', 'aplomo' => 'Pezuñas bien conformadas y sanas. Sin signos de sobrecrecimiento o desgaste asimétrico.'],
            ['tag' => 'TR-007', 'ce' => 39.0, 'cc' => 4.0, 'libido' => 'MUY_ALTA', 'aplomo' => 'Gran masa muscular con aplomos intachables. Excelente simetría testicular.'],
            ['tag' => 'TR-008', 'ce' => 35.0, 'cc' => 3.0, 'libido' => 'MEDIA', 'aplomo' => 'Aplomos estándar, garrones secos y firmes. Testículos elásticos y móviles.'],
            ['tag' => 'TR-009', 'ce' => 36.0, 'cc' => 3.5, 'libido' => 'ALTA', 'aplomo' => 'Buena conformación podal. Desplazamiento amplio y coordinado.'],
            ['tag' => 'TR-010', 'ce' => 37.0, 'cc' => 3.5, 'libido' => 'ALTA', 'aplomo' => 'Aplomos correctos para servicio en campo natural quebrado.'],
            ['tag' => 'TR-011', 'ce' => 34.0, 'cc' => 3.0, 'libido' => 'MEDIA', 'aplomo' => 'Aplomos normales, sin patologías articulares en rodillas ni garrones.'],
            ['tag' => 'TR-012', 'ce' => 36.5, 'cc' => 3.5, 'libido' => 'ALTA', 'aplomo' => 'Excelente paralelismo y fortaleza en tren posterior. Óptimo para salto.'],
            ['tag' => 'TR-013', 'ce' => 35.5, 'cc' => 3.5, 'libido' => 'ALTA', 'aplomo' => 'Pezuñas duras y bien aplomadas. Prepucio corto y limpio sin eversión.'],
            ['tag' => 'TR-014', 'ce' => 38.5, 'cc' => 3.5, 'libido' => 'MUY_ALTA', 'aplomo' => 'Destacada conformación andrológica y locomotora. CE y tono testicular sobresaliente.'],
            ['tag' => 'TR-015', 'ce' => 34.5, 'cc' => 3.0, 'libido' => 'MEDIA', 'aplomo' => 'Aplomos normales sin observaciones. Toro joven de reposición con buena aptitud.'],
        ];

        // Clean previous evaluations and samples to avoid orphaned seeds
        BullLabSample::withoutGlobalScopes()->where('company_id', $companyId)->delete();
        BullHealthEvaluation::withoutGlobalScopes()->where('company_id', $companyId)->delete();

        foreach ($aptBullsConfig as $index => $cfg) {
            $caravan = Caravan::withoutGlobalScopes()->where('identification', $cfg['tag'])->first();
            if (!$caravan) {
                $caravan = Caravan::create([
                    'company_id' => $companyId,
                    'identification' => $cfg['tag'],
                    'sex' => 'M',
                    'category_id' => $toroCategory->id,
                    'teeth' => 4,
                    'entry_weight' => 720.0,
                ]);
            }

            // -------------------------------------------------------------
            // HISTORIAL 1: Hace 12 Meses (Campaña Primavera 2025)
            // -------------------------------------------------------------
            $evalPast12 = BullHealthEvaluation::create([
                'company_id' => $companyId,
                'caravan_id' => $caravan->id,
                'last_evaluation_date' => now()->subMonths(12)->toDateString(),
                'aplomo_notes' => 'Revisación pre-servicio año anterior. Aplomos correctos.',
                'scrotal_circumference_cm' => $cfg['ce'] - 1.5,
                'body_condition_score' => 3.0,
                'libido' => $cfg['libido'],
                'status' => 'APT',
                'observations' => 'Evaluación andrológica anual satisfactoria 2025.',
            ]);

            // Muestras tomadas hace 12 meses (1º y 2º raspaje + serología completa)
            BullLabSample::create([
                'company_id' => $companyId,
                'caravan_id' => $caravan->id,
                'evaluation_id' => $evalPast12->id,
                'sample_type' => 'PREPUCE_SCRAPE',
                'sample_round' => 1,
                'sample_date' => now()->subMonths(12)->toDateString(),
                'tube_number' => 'R-25-' . sprintf('%03d', $index + 1),
                'status' => 'NEGATIVE_CLEARED',
                'protocol_number' => 'LAB-2025-1048',
                'result_date' => now()->subMonths(12)->addDays(6)->toDateString(),
                'notes' => 'Cultivo negativo para Tritrichomonas foetus y Campylobacter fetus.',
            ]);

            BullLabSample::create([
                'company_id' => $companyId,
                'caravan_id' => $caravan->id,
                'evaluation_id' => $evalPast12->id,
                'sample_type' => 'PREPUCE_SCRAPE',
                'sample_round' => 2,
                'sample_date' => now()->subMonths(12)->addDays(15)->toDateString(),
                'tube_number' => 'R-25-' . sprintf('%03d', $index + 50),
                'status' => 'NEGATIVE_CLEARED',
                'protocol_number' => 'LAB-2025-1192',
                'result_date' => now()->subMonths(12)->addDays(21)->toDateString(),
                'notes' => 'Segundo raspaje consecutivo negativo.',
            ]);

            BullLabSample::create([
                'company_id' => $companyId,
                'caravan_id' => $caravan->id,
                'evaluation_id' => $evalPast12->id,
                'sample_type' => 'BLOOD_SEROLOGY',
                'sample_round' => 1,
                'sample_date' => now()->subMonths(12)->toDateString(),
                'tube_number' => 'S-25-' . sprintf('%03d', $index + 1),
                'status' => 'NEGATIVE_CLEARED',
                'protocol_number' => 'SERO-2025-440',
                'result_date' => now()->subMonths(12)->addDays(5)->toDateString(),
                'notes' => 'BPA / Wright no reactores para Brucella abortus.',
            ]);

            // -------------------------------------------------------------
            // HISTORIAL 2: Hace 6 Meses (Control Serológico Otoño 2026)
            // -------------------------------------------------------------
            $evalPast6 = BullHealthEvaluation::create([
                'company_id' => $companyId,
                'caravan_id' => $caravan->id,
                'last_evaluation_date' => now()->subMonths(6)->toDateString(),
                'aplomo_notes' => 'Salida de servicio e invernada. Descanso.',
                'scrotal_circumference_cm' => $cfg['ce'] - 0.5,
                'body_condition_score' => 3.0,
                'libido' => 'MEDIA',
                'status' => 'APT',
                'observations' => 'Control sanitario semestral de mantenimiento.',
            ]);

            BullLabSample::create([
                'company_id' => $companyId,
                'caravan_id' => $caravan->id,
                'evaluation_id' => $evalPast6->id,
                'sample_type' => 'BLOOD_SEROLOGY',
                'sample_round' => 1,
                'sample_date' => now()->subMonths(6)->toDateString(),
                'tube_number' => 'S-26-OT-' . sprintf('%03d', $index + 1),
                'status' => 'NEGATIVE_CLEARED',
                'protocol_number' => 'SERO-2026-088',
                'result_date' => now()->subMonths(6)->addDays(4)->toDateString(),
                'notes' => 'Monitoreo serológico rutinario negativo.',
            ]);

            // -------------------------------------------------------------
            // EVALUACIÓN ACTUAL (Campaña Pre-Servicio 2026 - Hace 5 a 15 días)
            // -------------------------------------------------------------
            $isFullyCleared = ($index < 8); // Primeros 8 ya tienen resultados negativos concluidos
            $evalCurrentStatus = $isFullyCleared ? 'APT' : 'PENDING_EVALUATION';

            $evalCurrent = BullHealthEvaluation::create([
                'company_id' => $companyId,
                'caravan_id' => $caravan->id,
                'last_evaluation_date' => now()->subDays(rand(3, 10))->toDateString(),
                'aplomo_notes' => $cfg['aplomo'],
                'scrotal_circumference_cm' => $cfg['ce'],
                'body_condition_score' => $cfg['cc'],
                'libido' => $cfg['libido'],
                'status' => $evalCurrentStatus,
                'observations' => $isFullyCleared
                    ? 'Examen andrológico y raspajes concluidos negativos. Habilitado para entore.'
                    : 'Examen físico apto en manga. Muestras de raspaje y serología enviadas al laboratorio.',
            ]);

            if ($isFullyCleared) {
                // Toros con raspajes 1 y 2 + sangre concluidos NEGATIVOS
                BullLabSample::create([
                    'company_id' => $companyId,
                    'caravan_id' => $caravan->id,
                    'evaluation_id' => $evalCurrent->id,
                    'sample_type' => 'PREPUCE_SCRAPE',
                    'sample_round' => 1,
                    'sample_date' => now()->subDays(20)->toDateString(),
                    'tube_number' => 'R-01-' . sprintf('%02d', $index + 1),
                    'status' => 'NEGATIVE_CLEARED',
                    'protocol_number' => 'LAB-2026-890',
                    'result_date' => now()->subDays(14)->toDateString(),
                    'notes' => '1º Raspaje prepucial negativo.',
                ]);

                BullLabSample::create([
                    'company_id' => $companyId,
                    'caravan_id' => $caravan->id,
                    'evaluation_id' => $evalCurrent->id,
                    'sample_type' => 'PREPUCE_SCRAPE',
                    'sample_round' => 2,
                    'sample_date' => now()->subDays(10)->toDateString(),
                    'tube_number' => 'R-02-' . sprintf('%02d', $index + 1),
                    'status' => 'NEGATIVE_CLEARED',
                    'protocol_number' => 'LAB-2026-920',
                    'result_date' => now()->subDays(4)->toDateString(),
                    'notes' => '2º Raspaje prepucial negativo.',
                ]);

                BullLabSample::create([
                    'company_id' => $companyId,
                    'caravan_id' => $caravan->id,
                    'evaluation_id' => $evalCurrent->id,
                    'sample_type' => 'BLOOD_SEROLOGY',
                    'sample_round' => 1,
                    'sample_date' => now()->subDays(10)->toDateString(),
                    'tube_number' => 'S-01-' . sprintf('%02d', $index + 1),
                    'status' => 'NEGATIVE_CLEARED',
                    'protocol_number' => 'SERO-2026-701',
                    'result_date' => now()->subDays(4)->toDateString(),
                    'notes' => 'Serología negativa Brucelosis.',
                ]);
            } else {
                // Toros con muestras recién tomadas en manga: PENDIENTES DE RESULTADOS
                BullLabSample::create([
                    'company_id' => $companyId,
                    'caravan_id' => $caravan->id,
                    'evaluation_id' => $evalCurrent->id,
                    'sample_type' => 'PREPUCE_SCRAPE',
                    'sample_round' => 1,
                    'sample_date' => now()->subDays(rand(2, 5))->toDateString(),
                    'tube_number' => 'R-01-' . sprintf('%02d', $index + 1),
                    'status' => 'PENDING_RESULTS',
                    'protocol_number' => null,
                    'result_date' => null,
                    'notes' => 'Muestra de raspaje tomada en manga. Aguarda informe de cultivo.',
                ]);

                BullLabSample::create([
                    'company_id' => $companyId,
                    'caravan_id' => $caravan->id,
                    'evaluation_id' => $evalCurrent->id,
                    'sample_type' => 'BLOOD_SEROLOGY',
                    'sample_round' => 1,
                    'sample_date' => now()->subDays(rand(2, 5))->toDateString(),
                    'tube_number' => 'S-01-' . sprintf('%02d', $index + 1),
                    'status' => 'PENDING_RESULTS',
                    'protocol_number' => null,
                    'result_date' => null,
                    'notes' => 'Sangrado yugular tomado en manga. Aguarda BPA.',
                ]);
            }
        }

        // =======================================================
        // 15 TOROS NO APTOS (UNFIT / IN_TREATMENT) (TR-016 a TR-030)
        // =======================================================
        $nonAptBullsConfig = [
            // UNFIT: Venéreas detectadas por raspaje prepucial
            [
                'tag' => 'TR-016',
                'ce' => 34.0,
                'cc' => 3.0,
                'libido' => 'BAJA',
                'aplomo' => 'Aplomos normales.',
                'status' => 'UNFIT',
                'obs' => 'RECHAZO SANITARIO: Tritrichomonas foetus positiva en raspaje prepucial pre-servicio. Animal descarte (Carrillo p. 166).',
                'pathogen_code' => 'TRITRICHOMONAS_FOETUS',
                'diag_status' => 'CONFIRMED_POSITIVE',
                'sample_type' => 'PREPUCE_SCRAPE',
                'tube' => 'R-01-16',
                'protocol' => 'LAB-2026-894',
            ],
            [
                'tag' => 'TR-017',
                'ce' => 35.0,
                'cc' => 3.0,
                'libido' => 'MEDIA',
                'aplomo' => 'Aplomos normales.',
                'status' => 'UNFIT',
                'obs' => 'RECHAZO SANITARIO: Campylobacter fetus subsp. venerealis positivo en cultivo prepucial. Descarte obligatorio.',
                'pathogen_code' => 'CAMPYLOBACTER_FETUS',
                'diag_status' => 'CONFIRMED_POSITIVE',
                'sample_type' => 'PREPUCE_SCRAPE',
                'tube' => 'R-01-17',
                'protocol' => 'LAB-2026-895',
            ],
            // UNFIT: Serología positiva a Brucelosis
            [
                'tag' => 'TR-018',
                'ce' => 33.5,
                'cc' => 2.5,
                'libido' => 'BAJA',
                'aplomo' => 'Miembros posteriores rígidos.',
                'status' => 'UNFIT',
                'obs' => 'RECHAZO SANITARIO OFICIAL: Reactor positivo a Brucelosis bovina (BPA/FPA). Saneamiento obligatorio.',
                'pathogen_code' => 'BRUCELLA_ABORTUS',
                'diag_status' => 'CONFIRMED_POSITIVE',
                'sample_type' => 'BLOOD_SEROLOGY',
                'tube' => 'S-01-18',
                'protocol' => 'SERO-2026-999',
            ],
            [
                'tag' => 'TR-019',
                'ce' => 34.0,
                'cc' => 2.5,
                'libido' => 'BAJA',
                'aplomo' => 'Aplomos normales.',
                'status' => 'UNFIT',
                'obs' => 'RECHAZO SANITARIO OFICIAL: Reactor a prueba de tuberculina en pliegue anocaudal.',
                'pathogen_code' => 'MYCOBACTERIUM_BOVIS',
                'diag_status' => 'CONFIRMED_POSITIVE',
                'sample_type' => null,
                'tube' => null,
                'protocol' => null,
            ],
            // UNFIT: Descarte Andrológico por Biometría Escrotal Insuficiente
            [
                'tag' => 'TR-020',
                'ce' => 25.5,
                'cc' => 3.0,
                'libido' => 'BAJA',
                'aplomo' => 'Aplomos normales.',
                'status' => 'UNFIT',
                'obs' => 'DESCARTE ANDROLÓGICO: Hipoplasia testicular severa bilateral. CE de 25.5 cm inferior al umbral mínimo de 28.0 cm (Carrillo p. 170).',
                'pathogen_code' => null,
                'diag_status' => null,
                'sample_type' => null,
                'tube' => null,
                'protocol' => null,
            ],
            [
                'tag' => 'TR-021',
                'ce' => 26.5,
                'cc' => 3.0,
                'libido' => 'BAJA',
                'aplomo' => 'Aplomos normales.',
                'status' => 'UNFIT',
                'obs' => 'DESCARTE ANDROLÓGICO: Testículo izquierdo hipotrófico con marcada asimetría y consistencia blanda.',
                'pathogen_code' => null,
                'diag_status' => null,
                'sample_type' => null,
                'tube' => null,
                'protocol' => null,
            ],
            // UNFIT: Defectos Locomotores Graves
            [
                'tag' => 'TR-022',
                'ce' => 36.0,
                'cc' => 2.5,
                'libido' => 'BAJA',
                'aplomo' => 'Descarte locomotor: Artritis severa y deformación en tarso derecho con claudicación grado IV.',
                'status' => 'UNFIT',
                'obs' => 'DESCARTE LOCOMOTOR: Imposibilidad de soportar el peso sobre tren posterior durante la monta.',
                'pathogen_code' => null,
                'diag_status' => null,
                'sample_type' => null,
                'tube' => null,
                'protocol' => null,
            ],
            [
                'tag' => 'TR-023',
                'ce' => 35.0,
                'cc' => 3.0,
                'libido' => 'BAJA',
                'aplomo' => 'Garrón recto parado de patas (ángulo > 165 grados). Alto riesgo de ruptura de ligamentos (Carrillo p. 167).',
                'status' => 'UNFIT',
                'obs' => 'DESCARTE LOCOMOTOR: Defecto estructural de garrón recto descalificante.',
                'pathogen_code' => null,
                'diag_status' => null,
                'sample_type' => null,
                'tube' => null,
                'protocol' => null,
            ],
            [
                'tag' => 'TR-024',
                'ce' => 34.5,
                'cc' => 2.5,
                'libido' => 'BAJA',
                'aplomo' => 'Pezuñas en tijera sobrecrecidas con infosura crónica en miembro anterior izquierdo.',
                'status' => 'UNFIT',
                'obs' => 'DESCARTE LOCOMOTOR: Lesión podal irreversible.',
                'pathogen_code' => null,
                'diag_status' => null,
                'sample_type' => null,
                'tube' => null,
                'protocol' => null,
            ],
            [
                'tag' => 'TR-025',
                'ce' => 35.0,
                'cc' => 2.5,
                'libido' => 'BAJA',
                'aplomo' => 'Acolado marcado en ambos miembros posteriores con rotación hacia adentro.',
                'status' => 'UNFIT',
                'obs' => 'DESCARTE ESTRUCTURAL: Desviación valga de corvejones.',
                'pathogen_code' => null,
                'diag_status' => null,
                'sample_type' => null,
                'tube' => null,
                'protocol' => null,
            ],
            // IN_TREATMENT: Afecciones Clínicas Podales o Traumáticas
            [
                'tag' => 'TR-026',
                'ce' => 36.5,
                'cc' => 3.0,
                'libido' => 'MEDIA',
                'aplomo' => 'Pietín activo / Gabarro podal interdigital en miembro posterior izquierdo con inflamación.',
                'status' => 'IN_TREATMENT',
                'obs' => 'EN TRATAMIENTO: Dichelobacter nodosus / Fusobacterium necrophorum. Lavaje y desbridamiento.',
                'pathogen_code' => 'DICHELOBACTER_NODOSUS',
                'diag_status' => 'IN_TREATMENT',
                'sample_type' => null,
                'tube' => null,
                'protocol' => null,
            ],
            [
                'tag' => 'TR-027',
                'ce' => 35.5,
                'cc' => 3.0,
                'libido' => 'MEDIA',
                'aplomo' => 'Pietín leve en miembro anterior derecho. Vendaje con sulfato de cobre.',
                'status' => 'IN_TREATMENT',
                'obs' => 'EN TRATAMIENTO: Manejo podológico.',
                'pathogen_code' => 'DICHELOBACTER_NODOSUS',
                'diag_status' => 'IN_TREATMENT',
                'sample_type' => null,
                'tube' => null,
                'protocol' => null,
            ],
            [
                'tag' => 'TR-028',
                'ce' => 36.0,
                'cc' => 3.0,
                'libido' => 'MEDIA',
                'aplomo' => 'Aplomos correctos.',
                'status' => 'IN_TREATMENT',
                'obs' => 'EN TRATAMIENTO: Balanopostitis inespecífica traumática post-raspaje. Lavajes antisépticos.',
                'pathogen_code' => null,
                'diag_status' => null,
                'sample_type' => null,
                'tube' => null,
                'protocol' => null,
            ],
            [
                'tag' => 'TR-029',
                'ce' => 34.0,
                'cc' => 2.0,
                'libido' => 'BAJA',
                'aplomo' => 'Aplomos normales, pero presenta condición corporal deficiente (CC 2.0).',
                'status' => 'IN_TREATMENT',
                'obs' => 'EN TRATAMIENTO NUTRICIONAL: Requiere 45 días de suplementación forrajera concentrada para alcanzar CC óptima (Carrillo p. 165).',
                'pathogen_code' => null,
                'diag_status' => null,
                'sample_type' => null,
                'tube' => null,
                'protocol' => null,
            ],
            [
                'tag' => 'TR-030',
                'ce' => 35.0,
                'cc' => 3.0,
                'libido' => 'MEDIA',
                'aplomo' => 'Aplomos normales.',
                'status' => 'IN_TREATMENT',
                'obs' => 'EN TRATAMIENTO: Hematoma prepucial cerrado sin desgarro. Reposo reproductivo estricto.',
                'pathogen_code' => null,
                'diag_status' => null,
                'sample_type' => null,
                'tube' => null,
                'protocol' => null,
            ],
        ];

        foreach ($nonAptBullsConfig as $cfg) {
            $caravan = Caravan::withoutGlobalScopes()->where('identification', $cfg['tag'])->first();
            if (!$caravan) {
                $caravan = Caravan::create([
                    'company_id' => $companyId,
                    'identification' => $cfg['tag'],
                    'sex' => 'M',
                    'category_id' => $toroCategory->id,
                    'teeth' => 4,
                    'entry_weight' => 700.0,
                ]);
            }

            // Historial pasado (hace 12 meses estaba en observación o apto joven)
            BullHealthEvaluation::create([
                'company_id' => $companyId,
                'caravan_id' => $caravan->id,
                'last_evaluation_date' => now()->subMonths(12)->toDateString(),
                'aplomo_notes' => 'Evaluación histórica pre-servicio 2025.',
                'scrotal_circumference_cm' => $cfg['ce'] - 1.0,
                'body_condition_score' => 3.0,
                'libido' => 'MEDIA',
                'status' => 'APT',
                'observations' => 'Evaluación histórica previa de la campaña 2025.',
            ]);

            // Evaluación actual
            $evalCurrent = BullHealthEvaluation::create([
                'company_id' => $companyId,
                'caravan_id' => $caravan->id,
                'last_evaluation_date' => now()->subDays(rand(2, 8))->toDateString(),
                'aplomo_notes' => $cfg['aplomo'],
                'scrotal_circumference_cm' => $cfg['ce'],
                'body_condition_score' => $cfg['cc'],
                'libido' => $cfg['libido'],
                'status' => $cfg['status'],
                'observations' => $cfg['obs'],
            ]);

            // Si tiene muestra positiva asociada
            if (!empty($cfg['sample_type'])) {
                $pathogen = !empty($cfg['pathogen_code']) && isset($pathogens[$cfg['pathogen_code']])
                    ? $pathogens[$cfg['pathogen_code']]
                    : null;

                BullLabSample::create([
                    'company_id' => $companyId,
                    'caravan_id' => $caravan->id,
                    'evaluation_id' => $evalCurrent->id,
                    'sample_type' => $cfg['sample_type'],
                    'sample_round' => 1,
                    'sample_date' => now()->subDays(12)->toDateString(),
                    'tube_number' => $cfg['tube'],
                    'status' => 'POSITIVE_DETECTED',
                    'protocol_number' => $cfg['protocol'],
                    'result_date' => now()->subDays(3)->toDateString(),
                    'pathogen_id' => $pathogen?->id,
                    'notes' => 'Resultado positivo confirmado por laboratorio.',
                ]);
            }

            // Si tiene diagnóstico clínico persistente
            if (!empty($cfg['pathogen_code']) && isset($pathogens[$cfg['pathogen_code']])) {
                $pathogen = $pathogens[$cfg['pathogen_code']];
                VeterinaryDiagnosis::create([
                    'company_id' => $companyId,
                    'caravan_id' => $caravan->id,
                    'pathogen_id' => $pathogen->id,
                    'veterinarian_id' => $vetUser->id,
                    'diagnosis_date' => now()->subDays(rand(2, 6))->toDateString(),
                    'status' => $cfg['diag_status'] ?? 'CONFIRMED_POSITIVE',
                    'treatment_notes' => $cfg['obs'],
                    'source_context' => 'PRE_SERVICE',
                ]);
            }
        }
    }
}
