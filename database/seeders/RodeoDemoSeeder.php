<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\WeighingTypeCode;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Services\BatchWeightService;
use App\Models\Company;
use App\Models\WeighingType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The rodeo of a new tenant: 10 active own batches through the productive cycle, about 220 head.
 * Every animal has its category, breed, color, teeth and a weight history, and every batch its curve
 * from those weights, so no figure on screen is made up. The two services run on top of this rodeo:
 * see RodeoDemoServicesSeeder.
 *
 * Deterministic: tags and weights come out the same on every refresh. Tags carry the RX- prefix.
 */
class RodeoDemoSeeder extends Seeder
{
    public const BATCH_NORTE = 'Rodeo Cría Norte';
    public const BATCH_SUR = 'Rodeo Cría Sur';
    public const BATCH_BULLS = 'Torada';

    /** The weighings of the campaign, all before the services start. */
    private const CAMPAIGN = ['2026-05-20', '2026-07-15', '2026-08-28', '2026-09-20'];

    private const BIRTH_DATES = ['2026-08-05', '2026-08-10', '2026-08-15', '2026-08-20'];
    private const BIRTH_WEIGHT_KG = 34.0;
    private const CALF_DAILY_GAIN_KG = 0.85;

    private int $companyId;
    private int $nextTag = 1001;

    /** @var array<string, int> */
    private array $categories = [];

    /** @var array<string, int> */
    private array $subcategories = [];

    /** @var int[] */
    private array $breeds = [];

    /** @var int[] */
    private array $colors = [];

    /** @var int[] */
    private array $mothers = [];

    private int $motherTurn = 0;

    /** @var array<int, array<string, list<array<string, mixed>>>> batch id => day => its weight rows */
    private array $weighings = [];

    public function run(): void
    {
        $company = Company::query()->orderBy('id')->first();
        if ($company === null) {
            $this->command?->warn('RodeoDemoSeeder: no hay compañía cargada. Se omite.');

            return;
        }

        $this->companyId = (int) $company->id;
        app(ICompanyContext::class)->setCompanyId($this->companyId);

        $this->categories = DB::table('animal_categories')->pluck('id', 'code')->map(static fn ($id): int => (int) $id)->all();
        $this->subcategories = DB::table('animal_subcategories')->pluck('id', 'code')->map(static fn ($id): int => (int) $id)->all();
        $this->breeds = DB::table('breeds')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $this->colors = DB::table('colors')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        mt_srand(20261102);

        foreach ($this->rodeo() as $spec) {
            $batchId = $this->batch($spec);

            foreach ($spec['groups'] as $group) {
                $this->animals($batchId, $group);
            }

            if ($spec['name'] === 'Vacas con Cría al Pie') {
                $this->calvesAtFoot($batchId);
            }

            if ($spec['name'] === self::BATCH_BULLS) {
                $this->gatherBulls($batchId);
            }
        }

        $this->recordWeighings();

        $this->command?->info('RodeoDemoSeeder: 10 lotes activos con caravanas categorizadas y pesadas.');
    }

    /**
     * Weights are the ones at the end of the campaign; earlier weighings subtract the daily gain.
     *
     * @return list<array{name: string, activity: string, type: string, confined: bool, eats: bool, notes: string, groups: list<array<string, mixed>>}>
     */
    private function rodeo(): array
    {
        $cow = ['category' => 'VACA', 'subcategory' => 'RODEO_GENERAL', 'sex' => 'H', 'teeth' => 8, 'gain' => 0.25];
        $heifer = ['category' => 'VAQUILLONA', 'subcategory' => 'REPOSICION', 'sex' => 'H', 'teeth' => 2, 'gain' => 0.55];
        $steer = ['category' => 'NOVILLO', 'sex' => 'M', 'teeth' => 4];

        return [
            [
                'name' => self::BATCH_NORTE, 'activity' => 'CRIA', 'type' => 'OPERATIONAL', 'confined' => false, 'eats' => false,
                'notes' => 'Vientres de rodeo general. Entore de primavera.',
                'groups' => [$cow + ['count' => 30, 'weight' => 445.0]],
            ],
            [
                'name' => self::BATCH_SUR, 'activity' => 'CRIA', 'type' => 'OPERATIONAL', 'confined' => false, 'eats' => false,
                'notes' => 'Vientres de rodeo general. Entore de primavera.',
                'groups' => [$cow + ['count' => 24, 'weight' => 430.0]],
            ],
            [
                'name' => 'Vacas con Cría al Pie', 'activity' => 'CRIA', 'type' => 'OPERATIONAL', 'confined' => false, 'eats' => false,
                'notes' => 'Parición de agosto. Las crías están al pie de su madre.',
                'groups' => [$cow + ['count' => 14, 'weight' => 415.0]],
            ],
            [
                'name' => 'Vaquillonas 1er Servicio', 'activity' => 'CRIA', 'type' => 'OPERATIONAL', 'confined' => false, 'eats' => false,
                'notes' => 'Listas para entorar: todavía sin servicio.',
                'groups' => [$heifer + ['count' => 18, 'weight' => 340.0]],
            ],
            [
                'name' => 'Destete Otoño 2026', 'activity' => 'CRIA', 'type' => 'WEANING', 'confined' => false, 'eats' => true,
                'notes' => 'Terneros destetados en abril de la parición 2025.',
                'groups' => [
                    ['category' => 'TERNERO', 'sex' => 'H', 'teeth' => 0, 'gain' => 0.6, 'count' => 12, 'weight' => 205.0, 'born' => '2025-09-10', 'weaned' => '2026-04-15'],
                    ['category' => 'TERNERO', 'sex' => 'M', 'teeth' => 0, 'gain' => 0.7, 'count' => 12, 'weight' => 225.0, 'born' => '2025-09-10', 'weaned' => '2026-04-15'],
                ],
            ],
            [
                'name' => 'Recría Vaquillonas 2025', 'activity' => 'RECRIA', 'type' => 'GROWING_HEIFERS', 'confined' => false, 'eats' => true,
                'notes' => 'Reposición propia, generación 2025.',
                'groups' => [$heifer + ['count' => 16, 'weight' => 285.0, 'born' => '2025-02-15']],
            ],
            [
                'name' => 'Recría Novillitos 2025', 'activity' => 'RECRIA', 'type' => 'GROWING_STEERS', 'confined' => false, 'eats' => true,
                'notes' => 'Machos castrados de la parición 2025.',
                'groups' => [['category' => 'NOVILLITO', 'sex' => 'M', 'teeth' => 2, 'gain' => 0.65, 'count' => 18, 'weight' => 300.0, 'born' => '2025-02-15']],
            ],
            [
                'name' => 'Invernada Corral', 'activity' => 'INVERNADA', 'type' => 'OPERATIONAL', 'confined' => true, 'eats' => true,
                'notes' => 'Terminación a corral.',
                'groups' => [$steer + ['gain' => 1.1, 'count' => 20, 'weight' => 430.0]],
            ],
            [
                'name' => 'Invernada Pastura', 'activity' => 'INVERNADA', 'type' => 'OPERATIONAL', 'confined' => false, 'eats' => true,
                'notes' => 'Terminación a pasto con suplemento.',
                'groups' => [$steer + ['gain' => 0.75, 'count' => 15, 'weight' => 395.0]],
            ],
            [
                'name' => self::BATCH_BULLS, 'activity' => 'CRIA', 'type' => 'OPERATIONAL', 'confined' => false, 'eats' => false,
                'notes' => 'Los toros del establecimiento entre servicios.',
                // Besides the bulls of the sanitary examples, gathered below: these are the ones the
                // extraction acts are written on.
                'groups' => [['category' => 'TORO', 'sex' => 'M', 'teeth' => 6, 'gain' => 0.2, 'count' => 8, 'weight' => 750.0]],
            ],
        ];
    }

    /** @param array<string, mixed> $spec */
    private function batch(array $spec): int
    {
        return (int) DB::table('batches')->insertGetId([
            'company_id' => $this->companyId,
            'name' => $spec['name'],
            'farm_id' => null,
            'observaciones' => $spec['notes'],
            'activity_id' => DB::table('activities')->where('code', $spec['activity'])->value('id'),
            'batch_type_id' => DB::table('batch_types')->where('code', $spec['type'])->value('id'),
            'is_confined' => $spec['confined'],
            'knows_to_eat' => $spec['eats'],
            'is_active' => true,
            'is_system' => false,
            'created_at' => '2026-05-01 08:00:00',
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $group */
    private function animals(int $batchId, array $group): void
    {
        for ($i = 0; $i < $group['count']; $i++) {
            $caravanId = $this->caravan($batchId, $group);
            $this->history($batchId, $caravanId, $this->around((float) $group['weight']), (float) $group['gain']);

            if (isset($group['born'])) {
                DB::table('caravan_lineage')->insert([
                    'caravan_id' => $caravanId,
                    'mother_id' => $this->nextMother(),
                    'birth_date' => $group['born'],
                    'is_nursing' => false,
                    'nursing_ended_at' => $group['weaned'] ?? null,
                    'nursing_end_reason' => isset($group['weaned']) ? 'WEANED' : null,
                    'weaning_type' => isset($group['weaned']) ? 'TRADITIONAL' : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /** Born here: the mothers are the cows of the two rodeos, seeded first, taken in turn. */
    private function nextMother(): int
    {
        if ($this->mothers === []) {
            $this->mothers = DB::table('caravans')
                ->where('company_id', $this->companyId)
                ->where('category_id', $this->categories['VACA'])
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        return $this->mothers[$this->motherTurn++ % count($this->mothers)];
    }

    /** One calf at foot per cow of the batch, weighed at birth and at the last control. */
    private function calvesAtFoot(int $batchId): void
    {
        $mothers = DB::table('caravans')->where('batch_id', $batchId)->orderBy('id')->pluck('id');
        $lastDay = self::CAMPAIGN[count(self::CAMPAIGN) - 1];

        foreach ($mothers as $index => $motherId) {
            $born = self::BIRTH_DATES[$index % count(self::BIRTH_DATES)];
            $birthWeight = $this->around(self::BIRTH_WEIGHT_KG, 3.0);
            $calfId = $this->caravan($batchId, ['category' => 'TERNERO', 'sex' => $index % 2 === 0 ? 'H' : 'M', 'teeth' => 0], $born);

            DB::table('caravan_lineage')->insert([
                'caravan_id' => $calfId,
                'mother_id' => $motherId,
                'birth_date' => $born,
                'is_nursing' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->weighing($batchId, $calfId, $born, $birthWeight, WeighingTypeCode::BIRTH, 'PORTABLE_FIELD', 'BIRTH');
            $this->weighing(
                $batchId,
                $calfId,
                $lastDay,
                round($birthWeight + self::CALF_DAILY_GAIN_KG * $this->daysBetween($born, $lastDay), 1),
                WeighingTypeCode::CONTROL,
                'INDIVIDUAL',
                'MANUAL'
            );
        }
    }

    /** The bulls of the sanitary examples are the bulls of the establishment: they rest here. */
    private function gatherBulls(int $batchId): void
    {
        $bulls = DB::table('caravans')
            ->where('company_id', $this->companyId)
            ->whereNull('batch_id')
            ->where('category_id', $this->categories['TORO'])
            ->orderBy('id')
            ->pluck('id');

        foreach ($bulls as $bullId) {
            DB::table('caravans')->where('id', $bullId)->update([
                'batch_id' => $batchId,
                'batch_assigned_at' => '2026-05-01 08:00:00',
                'breed_id' => $this->pick($this->breeds),
                'color_id' => $this->pick($this->colors),
                'teeth' => 6,
                'entry_date' => '2024-03-15',
                'updated_at' => now(),
            ]);
            $this->history($batchId, (int) $bullId, $this->around(760.0, 45.0), 0.2);
        }
    }

    /** @param array<string, mixed> $group */
    private function caravan(int $batchId, array $group, ?string $entryDate = null): int
    {
        $entryDate ??= $group['born'] ?? '2024-03-15';

        return (int) DB::table('caravans')->insertGetId([
            'company_id' => $this->companyId,
            'batch_id' => $batchId,
            'batch_assigned_at' => max($entryDate, '2026-05-01') . ' 08:00:00',
            'identification' => 'RX-' . $this->nextTag++,
            'tagged_at' => $entryDate,
            'category_id' => $this->categories[$group['category']],
            'subcategory_id' => isset($group['subcategory']) ? $this->subcategories[$group['subcategory']] : null,
            'breed_id' => $this->pick($this->breeds),
            'color_id' => $this->pick($this->colors),
            'sex' => $group['sex'],
            'teeth' => $group['teeth'],
            'is_breeding_eligible' => true,
            'entry_date' => $entryDate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The weighings of the campaign, ending at the given weight. */
    private function history(int $batchId, int $caravanId, float $finalWeight, float $dailyGain): void
    {
        $lastDay = self::CAMPAIGN[count(self::CAMPAIGN) - 1];

        foreach (self::CAMPAIGN as $day) {
            $weight = round($finalWeight - $dailyGain * $this->daysBetween($day, $lastDay), 1);
            $this->weighing($batchId, $caravanId, $day, $weight, WeighingTypeCode::CONTROL, 'INDIVIDUAL', 'MANUAL');
        }
    }

    private function weighing(int $batchId, int $caravanId, string $day, float $weight, WeighingTypeCode $type, string $method, string $source): void
    {
        $this->weighings[$batchId][$day][] = [
            'caravan_id' => $caravanId,
            'weighing_type_id' => WeighingType::idOf($type),
            'weight' => $weight,
            'weighing_date' => $day,
            'method' => $method,
            'shrunk_condition' => 'FULL_WEIGHT',
            'source' => $source,
            'current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Day by day, as they would have been loaded: each batch recomputes from its animals after each
     * weighing, so its curve is the one those weights draw.
     */
    private function recordWeighings(): void
    {
        $service = app(BatchWeightService::class);

        foreach ($this->weighings as $batchId => $days) {
            ksort($days);

            foreach ($days as $day => $rows) {
                DB::table('caravan_weights')
                    ->whereIn('caravan_id', array_column($rows, 'caravan_id'))
                    ->update(['current' => false]);

                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table('caravan_weights')->insert($chunk);
                }

                $service->recalculateBatchWeight(
                    $batchId,
                    BatchWeightCause::CONTROL,
                    new \DateTimeImmutable($day),
                    WeighingTypeCode::CONTROL
                );
            }
        }
    }

    /** No two animals of a group weigh the same. */
    private function around(float $weight, float $spread = 28.0): float
    {
        return round($weight + mt_rand((int) (-$spread * 10), (int) ($spread * 10)) / 10, 1);
    }

    /** @param int[] $ids */
    private function pick(array $ids): ?int
    {
        return $ids === [] ? null : $ids[mt_rand(0, count($ids) - 1)];
    }

    private function daysBetween(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days;
    }
}
