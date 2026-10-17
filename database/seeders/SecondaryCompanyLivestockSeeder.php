<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Enums\AnimalCategory as AnimalCategoryEnum;
use App\Core\Enums\WeighingTypeCode;
use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\AnimalSubcategory;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\BatchWeight;
use App\Models\Breed;
use App\Models\Caravan;
use App\Models\CaravanWeight;
use App\Models\Color;
use App\Models\Company;
use App\Models\Farm;
use App\Models\FemaleCaravanDetail;
use App\Models\Provider;
use App\Models\WeighingType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * "Hacienda Secundaria" as a clean company to walk the breeding flow from the start:
 *
 *  - "Lote Cría Inicial": five empty cows (cría, operational).
 *  - "Lote Cría 2": three more empty cows (cría, operational), a second herd to service apart.
 *  - "Toros": three bulls of the same company (cría), with no sanitary history of their own.
 *  - One provider with its two origin farms, for purchases into this company.
 *
 * Own batches have no farm (the company is the farm); `farms` only holds providers' origin fields.
 *
 * Re-runnable without undoing the flow: a batch or an animal that already exists is left as the
 * app left it (its batch, its reproductive state, its weights). Only what is missing is created.
 */
class SecondaryCompanyLivestockSeeder extends Seeder
{
    private const COMPANY_NAME = 'Hacienda Secundaria';
    private const RENSPA = '09.08.07.0002';

    private const PROVIDER = [
        'cuit' => '30-71888222-4',
        'name' => 'Ganadera Los Algarrobos S.A.',
        'commercial_name' => 'Los Algarrobos',
        'location' => 'Ruta Nacional 35, Km 610 (Córdoba)',
        'phone' => '+54 358 400-1122',
    ];

    /** name => [renspa, location] */
    private const PROVIDER_FARMS = [
        'Estancia Los Algarrobos' => ['14.035.0.00777/00', 'Vicuña Mackenna, Córdoba'],
        'Campo La Aguada' => ['14.035.0.00777/01', 'Sampacho, Córdoba'],
    ];

    /**
     * Each batch: activity, type, notes and its animals (tag => [category, subcategory, sex, teeth, weight]).
     */
    private const BATCHES = [
        'Lote Cría Inicial' => [
            'activity' => 'CRIA',
            'type' => 'OPERATIONAL',
            'notes' => 'Vientres de cría vacíos, listos para entorar.',
            'animals' => [
                'SEC-VAC-001' => ['VACA', 'RODEO_GENERAL', 'H', 4, 430.0],
                'SEC-VAC-002' => ['VACA', 'RODEO_GENERAL', 'H', 6, 445.0],
                'SEC-VAC-003' => ['VACA', 'RODEO_GENERAL', 'H', 4, 422.0],
                'SEC-VAC-004' => ['VACA', 'RODEO_GENERAL', 'H', 6, 450.0],
                'SEC-VAC-005' => ['VACA', 'RODEO_GENERAL', 'H', 4, 438.0],
            ],
        ],
        'Lote Cría 2' => [
            'activity' => 'CRIA',
            'type' => 'OPERATIONAL',
            'notes' => 'Segundo rodeo de vientres de cría vacíos.',
            'animals' => [
                'SEC-VAC-006' => ['VACA', 'RODEO_GENERAL', 'H', 4, 428.0],
                'SEC-VAC-007' => ['VACA', 'RODEO_GENERAL', 'H', 6, 441.0],
                'SEC-VAC-008' => ['VACA', 'RODEO_GENERAL', 'H', 4, 433.0],
            ],
        ],
        'Toros' => [
            'activity' => 'CRIA',
            'type' => 'OPERATIONAL',
            'notes' => 'Toros reproductores del rodeo de cría.',
            'animals' => [
                'SEC-TORO-001' => ['TORO', null, 'M', 6, 720.0],
                'SEC-TORO-002' => ['TORO', null, 'M', 6, 745.0],
                'SEC-TORO-003' => ['TORO', null, 'M', 8, 760.0],
            ],
        ],
    ];

    public function run(): void
    {
        $company = Company::where('name', self::COMPANY_NAME)->first();

        if (!$company) {
            $this->command?->warn(sprintf('SecondaryCompanyLivestockSeeder: "%s" was not found. Skipping.', self::COMPANY_NAME));

            return;
        }

        $companyId = (int) $company->id;

        $this->seedProvider($companyId);

        $now = Carbon::now();
        $breedIds = Breed::withoutGlobalScopes()->pluck('id')->all();
        $colorIds = Color::withoutGlobalScopes()->pluck('id')->all();
        $index = 0;

        foreach (self::BATCHES as $name => $definition) {
            $activity = Activity::withoutGlobalScopes()->where('code', $definition['activity'])->first();
            $batch = Batch::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $companyId, 'name' => $name],
                [
                    'farm_id' => null,
                    'activity_id' => $activity?->id,
                    'batch_type_id' => BatchType::withoutGlobalScopes()->where('code', $definition['type'])->value('id'),
                    'is_confined' => false,
                    'observaciones' => $definition['notes'],
                    'is_active' => true,
                    'is_system' => false,
                ]
            );

            $weights = [];

            foreach ($definition['animals'] as $tag => [$categoryCode, $subcategoryCode, $sex, $teeth, $weight]) {
                if (Caravan::withoutGlobalScopes()->where('identification', $tag)->exists()) {
                    $index++;
                    continue;
                }

                $caravan = Caravan::withoutGlobalScopes()->create(
                    [
                        'identification' => $tag,
                        'company_id' => $companyId,
                        'batch_id' => $batch->id,
                        'category_id' => AnimalCategory::withoutGlobalScopes()->where('code', $categoryCode)->value('id'),
                        'subcategory_id' => $subcategoryCode
                            ? AnimalSubcategory::withoutGlobalScopes()->where('code', $subcategoryCode)->value('id')
                            : null,
                        'breed_id' => $breedIds === [] ? null : $breedIds[$index % count($breedIds)],
                        'color_id' => $colorIds === [] ? null : $colorIds[$index % count($colorIds)],
                        'sex' => $sex,
                        'teeth' => $teeth,
                        'entry_weight' => $weight,
                        'renspa' => self::RENSPA,
                        'provider_id' => null,
                        'entry_date' => $now->copy()->subDays(60 + $index)->toDateString(),
                    ]
                );
                $index++;
                $weights[] = $weight;

                CaravanWeight::create(
                    [
                        'caravan_id' => $caravan->id,
                        'current' => true,
                        'weighing_type_id' => WeighingType::idOf(WeighingTypeCode::CONTROL),
                        'weight' => $weight,
                        'weighing_date' => $now->copy()->subDays(15)->toDateString(),
                        'notes' => 'Pesaje de control inicial Hacienda Secundaria',
                    ]
                );

                if ($sex === 'H') {
                    FemaleCaravanDetail::create(['caravan_id' => $caravan->id, 'is_empty' => true, 'arrival_category' => AnimalCategoryEnum::VACA]);
                }
            }

            // Only when this run put animals in: an existing batch keeps the metrics the app computed.
            if ($weights !== []) {
                $this->updateBatchMetrics($batch, (int) ($activity?->id ?? 1), $now);
            }
        }

        $this->command?->info(sprintf(
            'SecondaryCompanyLivestockSeeder: "%s" con %s y el proveedor %s (%d campos).',
            self::COMPANY_NAME,
            implode(', ', array_map(fn (string $name) => sprintf('"%s" (%d)', $name, count(self::BATCHES[$name]['animals'])), array_keys(self::BATCHES))),
            self::PROVIDER['name'],
            count(self::PROVIDER_FARMS)
        ));
    }

    private function seedProvider(int $companyId): void
    {
        $provider = Provider::updateOrCreate(
            ['cuit' => self::PROVIDER['cuit']],
            [
                'name' => self::PROVIDER['name'],
                'commercial_name' => self::PROVIDER['commercial_name'],
                'location' => self::PROVIDER['location'],
                'phone' => self::PROVIDER['phone'],
                'is_active' => true,
            ]
        );

        foreach (self::PROVIDER_FARMS as $name => [$renspa, $location]) {
            Farm::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $companyId, 'provider_id' => $provider->id, 'name' => $name],
                ['renspa' => $renspa, 'location' => $location, 'is_active' => true]
            );
        }
    }

    /**
     * The batch totals from the current weight of every animal in it now.
     */
    private function updateBatchMetrics(Batch $batch, int $activityId, Carbon $now): void
    {
        $caravanIds = Caravan::withoutGlobalScopes()->where('batch_id', $batch->id)->pluck('id');
        $weights = CaravanWeight::whereIn('caravan_id', $caravanIds)->where('current', true)->pluck('weight')->map(fn ($w) => (float) $w);
        $weighed = $weights->count();
        $average = $weighed > 0 ? round($weights->avg(), 2) : 0.0;

        $batch->update([
            'current_weight' => $average,
            'total_weight' => $weights->sum(),
            'caravans_count' => $caravanIds->count(),
            'weighed_count' => $weighed,
            'min_weight' => $weighed > 0 ? $weights->min() : 0.0,
            'max_weight' => $weighed > 0 ? $weights->max() : 0.0,
        ]);

        // Historical entry for the weight charts and curves, once per batch.
        BatchWeight::firstOrCreate(
            ['batch_id' => $batch->id, 'type' => 'INITIAL'],
            ['activity_id' => $activityId, 'weight' => $average, 'weighing_date' => $now->copy()->subDays(15)->toDateString()]
        );
    }
}
