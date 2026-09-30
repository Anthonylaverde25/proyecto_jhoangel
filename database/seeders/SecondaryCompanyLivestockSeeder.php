<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Enums\AnimalCategory as AnimalCategoryEnum;
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
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeder to populate secondary company (Hacienda Secundaria) with complete livestock hierarchy:
 * 1 own farm, 2 batches per activity (Cría, Recría, Invernada) typed with canonical batch types,
 * including replacement types, and 5 tagged animals per batch (30 animals total).
 */
class SecondaryCompanyLivestockSeeder extends Seeder
{
    private const COMPANY_NAME = 'Hacienda Secundaria';
    private const FARM_NAME = 'Establecimiento El Porvenir';
    private const RENSPA = '09.08.07.0002/01';

    public function run(): void
    {
        $company = Company::where('name', self::COMPANY_NAME)->first();

        if (!$company) {
            $this->command?->warn(sprintf(
                'SecondaryCompanyLivestockSeeder: "%s" was not found. Skipping.',
                self::COMPANY_NAME
            ));

            return;
        }

        $companyId = (int) $company->id;

        // 1. Create or resolve own farm for Hacienda Secundaria
        $farm = Farm::withoutGlobalScopes()->updateOrCreate(
            [
                'company_id' => $companyId,
                'name' => self::FARM_NAME,
            ],
            [
                'location' => 'Ruta Provincial 10, Km 45',
                'renspa' => self::RENSPA,
                'provider_id' => null,
                'is_active' => true,
            ]
        );

        // 2. Resolve Master Catalogues
        $activities = Activity::withoutGlobalScopes()
            ->whereIn('code', ['CRIA', 'RECRIA', 'INVERNADA'])
            ->get()
            ->keyBy('code');

        $batchTypes = BatchType::withoutGlobalScopes()
            ->whereIn('code', [
                'SERVICE',
                'WEANING',
                'GROWING_REPLACEMENT_FEMALES',
                'GROWING_REPLACEMENT_BULLS',
                'OPERATIONAL',
            ])
            ->get()
            ->keyBy('code');

        $categories = AnimalCategory::withoutGlobalScopes()
            ->whereIn('code', ['VACA', 'VAQUILLONA', 'TERNERO', 'TORITO', 'NOVILLO'])
            ->get()
            ->keyBy('code');

        $subcategories = AnimalSubcategory::withoutGlobalScopes()
            ->whereIn('code', ['RODEO_GENERAL', 'REPOSICION'])
            ->get()
            ->keyBy('code');

        $breedIds = Breed::withoutGlobalScopes()->pluck('id')->all();
        $colorIds = Color::withoutGlobalScopes()->pluck('id')->all();

        // 3. Batch Specifications (2 per activity)
        $batchDefinitions = [
            // --- Cría ---
            [
                'name' => 'Rodeo de Servicio 1 (Secundaria)',
                'activity_code' => 'CRIA',
                'batch_type_code' => 'SERVICE',
                'is_confined' => false,
                'observaciones' => 'Lote reproductivo homogéneo de vientres de cría para servicio.',
                'animals' => [
                    [
                        'tag' => 'SEC-CRI-001',
                        'cat' => 'VACA',
                        'sub' => 'RODEO_GENERAL',
                        'sex' => 'H',
                        'teeth' => 4,
                        'weight' => 430.0,
                        'arrival_cat' => AnimalCategoryEnum::VACA,
                    ],
                    [
                        'tag' => 'SEC-CRI-002',
                        'cat' => 'VACA',
                        'sub' => 'RODEO_GENERAL',
                        'sex' => 'H',
                        'teeth' => 6,
                        'weight' => 445.0,
                        'arrival_cat' => AnimalCategoryEnum::VACA,
                    ],
                    [
                        'tag' => 'SEC-CRI-003',
                        'cat' => 'VACA',
                        'sub' => 'RODEO_GENERAL',
                        'sex' => 'H',
                        'teeth' => 4,
                        'weight' => 422.0,
                        'arrival_cat' => AnimalCategoryEnum::VACA,
                    ],
                    [
                        'tag' => 'SEC-CRI-004',
                        'cat' => 'VACA',
                        'sub' => 'RODEO_GENERAL',
                        'sex' => 'H',
                        'teeth' => 6,
                        'weight' => 450.0,
                        'arrival_cat' => AnimalCategoryEnum::VACA,
                    ],
                    [
                        'tag' => 'SEC-CRI-005',
                        'cat' => 'VACA',
                        'sub' => 'RODEO_GENERAL',
                        'sex' => 'H',
                        'teeth' => 4,
                        'weight' => 438.0,
                        'arrival_cat' => AnimalCategoryEnum::VACA,
                    ],
                ],
            ],
            [
                'name' => 'Lote de Destete 1 (Secundaria)',
                'activity_code' => 'CRIA',
                'batch_type_code' => 'WEANING',
                'is_confined' => true,
                'observaciones' => 'Lote transitorio de terneros desmadrados en corral de acostumbramiento.',
                'animals' => [
                    [
                        'tag' => 'SEC-CRI-006',
                        'cat' => 'TERNERO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 0,
                        'weight' => 165.0,
                        'arrival_cat' => null,
                    ],
                    [
                        'tag' => 'SEC-CRI-007',
                        'cat' => 'TERNERO',
                        'sub' => null,
                        'sex' => 'H',
                        'teeth' => 0,
                        'weight' => 158.0,
                        'arrival_cat' => AnimalCategoryEnum::TERNERA,
                    ],
                    [
                        'tag' => 'SEC-CRI-008',
                        'cat' => 'TERNERO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 0,
                        'weight' => 172.0,
                        'arrival_cat' => null,
                    ],
                    [
                        'tag' => 'SEC-CRI-009',
                        'cat' => 'TERNERO',
                        'sub' => null,
                        'sex' => 'H',
                        'teeth' => 0,
                        'weight' => 160.0,
                        'arrival_cat' => AnimalCategoryEnum::TERNERA,
                    ],
                    [
                        'tag' => 'SEC-CRI-010',
                        'cat' => 'TERNERO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 0,
                        'weight' => 168.0,
                        'arrival_cat' => null,
                    ],
                ],
            ],

            // --- Recría (Lotes de Reposición) ---
            [
                'name' => 'Vientres de Reposición (Secundaria)',
                'activity_code' => 'RECRIA',
                'batch_type_code' => 'GROWING_REPLACEMENT_FEMALES',
                'is_confined' => false,
                'observaciones' => 'Hembras retenidas para reemplazar vientres de descarte del rodeo.',
                'animals' => [
                    [
                        'tag' => 'SEC-REC-001',
                        'cat' => 'VAQUILLONA',
                        'sub' => 'REPOSICION',
                        'sex' => 'H',
                        'teeth' => 2,
                        'weight' => 280.0,
                        'arrival_cat' => AnimalCategoryEnum::VAQUILLONA,
                    ],
                    [
                        'tag' => 'SEC-REC-002',
                        'cat' => 'VAQUILLONA',
                        'sub' => 'REPOSICION',
                        'sex' => 'H',
                        'teeth' => 2,
                        'weight' => 295.0,
                        'arrival_cat' => AnimalCategoryEnum::VAQUILLONA,
                    ],
                    [
                        'tag' => 'SEC-REC-003',
                        'cat' => 'VAQUILLONA',
                        'sub' => 'REPOSICION',
                        'sex' => 'H',
                        'teeth' => 2,
                        'weight' => 285.0,
                        'arrival_cat' => AnimalCategoryEnum::VAQUILLONA,
                    ],
                    [
                        'tag' => 'SEC-REC-004',
                        'cat' => 'VAQUILLONA',
                        'sub' => 'REPOSICION',
                        'sex' => 'H',
                        'teeth' => 2,
                        'weight' => 302.0,
                        'arrival_cat' => AnimalCategoryEnum::VAQUILLONA,
                    ],
                    [
                        'tag' => 'SEC-REC-005',
                        'cat' => 'VAQUILLONA',
                        'sub' => 'REPOSICION',
                        'sex' => 'H',
                        'teeth' => 2,
                        'weight' => 290.0,
                        'arrival_cat' => AnimalCategoryEnum::VAQUILLONA,
                    ],
                ],
            ],
            [
                'name' => 'Toritos de Reposición (Secundaria)',
                'activity_code' => 'RECRIA',
                'batch_type_code' => 'GROWING_REPLACEMENT_BULLS',
                'is_confined' => false,
                'observaciones' => 'Machos enteros de alto mérito genético destinados a futuros toros padres.',
                'animals' => [
                    [
                        'tag' => 'SEC-REC-006',
                        'cat' => 'TORITO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 2,
                        'weight' => 315.0,
                        'arrival_cat' => null,
                    ],
                    [
                        'tag' => 'SEC-REC-007',
                        'cat' => 'TORITO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 2,
                        'weight' => 330.0,
                        'arrival_cat' => null,
                    ],
                    [
                        'tag' => 'SEC-REC-008',
                        'cat' => 'TORITO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 2,
                        'weight' => 322.0,
                        'arrival_cat' => null,
                    ],
                    [
                        'tag' => 'SEC-REC-009',
                        'cat' => 'TORITO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 2,
                        'weight' => 342.0,
                        'arrival_cat' => null,
                    ],
                    [
                        'tag' => 'SEC-REC-010',
                        'cat' => 'TORITO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 2,
                        'weight' => 326.0,
                        'arrival_cat' => null,
                    ],
                ],
            ],

            // --- Invernada ---
            [
                'name' => 'Corral Invernada 1 (Secundaria)',
                'activity_code' => 'INVERNADA',
                'batch_type_code' => 'OPERATIONAL',
                'is_confined' => true,
                'observaciones' => 'Lote de novillos en engorde intensivo a corral (feedlot).',
                'animals' => [
                    [
                        'tag' => 'SEC-INV-001',
                        'cat' => 'NOVILLO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 2,
                        'weight' => 355.0,
                        'arrival_cat' => null,
                    ],
                    [
                        'tag' => 'SEC-INV-002',
                        'cat' => 'NOVILLO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 4,
                        'weight' => 368.0,
                        'arrival_cat' => null,
                    ],
                    [
                        'tag' => 'SEC-INV-003',
                        'cat' => 'NOVILLO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 2,
                        'weight' => 360.0,
                        'arrival_cat' => null,
                    ],
                    [
                        'tag' => 'SEC-INV-004',
                        'cat' => 'NOVILLO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 4,
                        'weight' => 376.0,
                        'arrival_cat' => null,
                    ],
                    [
                        'tag' => 'SEC-INV-005',
                        'cat' => 'NOVILLO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 2,
                        'weight' => 364.0,
                        'arrival_cat' => null,
                    ],
                ],
            ],
            [
                'name' => 'Terminación Pastura (Secundaria)',
                'activity_code' => 'INVERNADA',
                'batch_type_code' => 'OPERATIONAL',
                'is_confined' => false,
                'observaciones' => 'Lote de novillos pesados en terminación pastoril.',
                'animals' => [
                    [
                        'tag' => 'SEC-INV-006',
                        'cat' => 'NOVILLO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 4,
                        'weight' => 415.0,
                        'arrival_cat' => null,
                    ],
                    [
                        'tag' => 'SEC-INV-007',
                        'cat' => 'NOVILLO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 4,
                        'weight' => 428.0,
                        'arrival_cat' => null,
                    ],
                    [
                        'tag' => 'SEC-INV-008',
                        'cat' => 'NOVILLO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 4,
                        'weight' => 420.0,
                        'arrival_cat' => null,
                    ],
                    [
                        'tag' => 'SEC-INV-009',
                        'cat' => 'NOVILLO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 4,
                        'weight' => 438.0,
                        'arrival_cat' => null,
                    ],
                    [
                        'tag' => 'SEC-INV-010',
                        'cat' => 'NOVILLO',
                        'sub' => null,
                        'sex' => 'M',
                        'teeth' => 4,
                        'weight' => 424.0,
                        'arrival_cat' => null,
                    ],
                ],
            ],
        ];

        // 4. Seed Batches and Animals
        $now = Carbon::now();
        $breedCount = count($breedIds);
        $colorCount = count($colorIds);
        $animalIndex = 0;

        foreach ($batchDefinitions as $batchDef) {
            $activity = $activities->get($batchDef['activity_code']);
            $batchType = $batchTypes->get($batchDef['batch_type_code']);

            $batch = Batch::withoutGlobalScopes()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'name' => $batchDef['name'],
                ],
                [
                    'farm_id' => $farm->id,
                    'activity_id' => $activity?->id,
                    'batch_type_id' => $batchType?->id,
                    'is_confined' => $batchDef['is_confined'],
                    'observaciones' => $batchDef['observaciones'],
                    'is_active' => true,
                    'is_system' => false,
                ]
            );

            $batchWeightsList = [];
            $batchId = (int) $batch->id;

            foreach ($batchDef['animals'] as $animalData) {
                $category = $categories->get($animalData['cat']);
                $subcategory = $animalData['sub'] ? $subcategories->get($animalData['sub']) : null;

                $breedId = $breedCount > 0 ? $breedIds[$animalIndex % $breedCount] : null;
                $colorId = $colorCount > 0 ? $colorIds[$animalIndex % $colorCount] : null;
                $animalIndex++;

                $weightVal = (float) $animalData['weight'];
                $batchWeightsList[] = $weightVal;

                $caravan = Caravan::withoutGlobalScopes()->updateOrCreate(
                    [
                        'identification' => $animalData['tag'],
                    ],
                    [
                        'company_id' => $companyId,
                        'batch_id' => $batchId,
                        'category_id' => $category?->id,
                        'subcategory_id' => $subcategory?->id,
                        'breed_id' => $breedId,
                        'color_id' => $colorId,
                        'sex' => $animalData['sex'],
                        'teeth' => $animalData['teeth'],
                        'entry_weight' => $weightVal,
                        'renspa' => self::RENSPA,
                        'provider_id' => null,
                        'entry_date' => $now->copy()->subDays(rand(45, 90))->toDateString(),
                    ]
                );

                // Caravan individual weight record
                CaravanWeight::updateOrCreate(
                    [
                        'caravan_id' => $caravan->id,
                        'current' => true,
                    ],
                    [
                        'weight' => $weightVal,
                        'weighing_date' => $now->copy()->subDays(15)->toDateString(),
                        'notes' => 'Pesaje de control inicial Hacienda Secundaria',
                    ]
                );

                // Female details if female
                if ($animalData['sex'] === 'H' && $animalData['arrival_cat']) {
                    FemaleCaravanDetail::updateOrCreate(
                        [
                            'caravan_id' => $caravan->id,
                        ],
                        [
                            'is_empty' => true,
                            'arrival_category' => $animalData['arrival_cat'],
                        ]
                    );
                }
            }

            // 5. Update batch totals and compute metrics
            $caravansCount = count($batchWeightsList);
            $totalWeight = array_sum($batchWeightsList);
            $avgWeight = $caravansCount > 0 ? round($totalWeight / $caravansCount, 2) : 0.0;
            $minWeight = $caravansCount > 0 ? min($batchWeightsList) : 0.0;
            $maxWeight = $caravansCount > 0 ? max($batchWeightsList) : 0.0;

            $batch->update([
                'current_weight' => $avgWeight,
                'total_weight' => $totalWeight,
                'caravans_count' => $caravansCount,
                'weighed_count' => $caravansCount,
                'min_weight' => $minWeight,
                'max_weight' => $maxWeight,
            ]);

            // Historical weight entry for charts and curves
            BatchWeight::updateOrCreate(
                [
                    'batch_id' => $batchId,
                    'type' => 'INITIAL',
                ],
                [
                    'activity_id' => $activity?->id ?? 1,
                    'weight' => $avgWeight,
                    'weighing_date' => $now->copy()->subDays(15)->toDateString(),
                ]
            );
        }

        $this->command?->info(sprintf(
            'SecondaryCompanyLivestockSeeder: Seeded 6 batches and 30 caravans for "%s" in "%s".',
            self::COMPANY_NAME,
            self::FARM_NAME
        ));
    }
}
