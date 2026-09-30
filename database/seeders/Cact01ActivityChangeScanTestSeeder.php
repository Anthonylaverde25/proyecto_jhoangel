<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\AnimalSubcategory;
use App\Models\Batch;
use App\Models\BatchWeight;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;
use App\Models\Company;
use App\Models\TransferOrder;
use App\Models\TransferOrderAnimal;
use App\Models\TransferOrderDestination;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * El escenario de las planillas CACT-01 que se escanean para probar el agente de IA
 * (`ai-agent/image_test/cact001/cact01_1x_*.png`, generadas por
 * `ai-agent/scripts/generate_cact01_images.py`).
 *
 * Es un escenario cerrado y reconocible a simple vista: un lote por etapa productiva, y
 * caravanas con un prefijo propio, para que al mirar un movimiento se sepa de inmediato que
 * viene de esta prueba y no de los datos de demostración.
 *
 * Los nombres son cortos a propósito. En una planilla de destino por animal el lote se escribe
 * a mano en una celda angosta, así que un nombre largo se sale del renglón y el escaneo lo lee
 * partido: "Test CACT" identifica la prueba en dos palabras y deja lugar para la etapa.
 *
 *  - "Test CACT Cría" (Cría, pastura): el ORIGEN. Contiene
 *    GRO-001-CAR-001..026 con su peso base y su dentición.
 *  - "Test CACT Recría" (Recría, pastura): destino existente de la misma
 *    etapa que declaran las planillas válidas.
 *  - "Test CACT Recría Corral" (Recría, corral): segundo destino
 *    existente, declarado a corral, para que la celda M pueda coincidir en una planilla y
 *    contradecir en otra.
 *  - "Test CACT Invernada" (Invernada, corral): existe a propósito en
 *    OTRA etapa productiva. Ninguna planilla válida lo usa: está para que una planilla que
 *    lo escriba a mano sea rechazada por actividad, que es la regla que se está probando.
 *
 *  - GRO-001-CAR-025: con dentición 4D, para que la planilla `cact01_25_*` escriba 2D y
 *    muestre TEETH_REGRESSION.
 *  - GRO-001-CAR-026: con una pesada VIGENTE del {@see self::LATE_WEIGHING_DATE}, posterior a
 *    la fecha de la planilla `cact01_29_*`: la pesada de la manga tiene que entrar a la historia
 *    sin desplazar a la vigente.
 *
 * Los nombres de lote que las planillas de "lote inexistente" escriben a mano
 * ({@see self::SHEET_CREATED_BATCHES}) NO se siembran: el punto de esas imágenes es que el
 * agente los proponga como lotes a crear. Por eso cada corrida BORRA los que haya dejado una
 * carga anterior, junto con los movimientos de los animales y la curva de los lotes del
 * escenario: sin eso la segunda carga de `cact01_11_*` encontraría el lote y dejaría de probar
 * lo que prueba. También borra las órdenes REGISTERED de esos lotes: cada planilla cargada sin
 * orden crea una al confirmarse.
 *
 * Además siembra la orden de transferencia EMITIDA {@see self::ORDER_CODE}: los animales
 * 017..024 hacia "Test CACT Recría". Es contra la que resuelven las planillas
 * `cact01_16_orden_completa.png` (las ocho cabezas: la deja EJECUTADA) y
 * `cact01_17_orden_parcial.png` (sólo cuatro: la deja PARCIAL). Los animales 001..016 quedan
 * libres para armar otras órdenes desde /transfer.
 *
 * Y dos órdenes más para la columna C/S nueva (categoría variable):
 *  - {@see self::AT_CHUTE_ORDER_CODE}: 007..010, la categoría se decide en la manga (AT_CHUTE).
 *  - {@see self::DECLARED_ORDER_CODE}: 011..016, la categoría la declara la orden (DECLARED):
 *    los machos pasan a Novillito y las hembras a Vaquillona / Reposición.
 *  - {@see self::PER_ANIMAL_AT_CHUTE_ORDER_CODE}: 001..006, destino POR ANIMAL decidido en la
 *    manga y categoría también en la manga: la hoja más ancha (lote destino, M y C/S nueva).
 * Cada corrida devuelve a todos los animales a Ternero sin subcategoría.
 *
 * Es idempotente: al volver a correrlo cada animal vuelve a su lote con su peso base, así que
 * una planilla ya cargada se puede volver a cargar sin `migrate:fresh`.
 *     php artisan tenants:seed --class=Cact01ActivityChangeScanTestSeeder
 */
class Cact01ActivityChangeScanTestSeeder extends Seeder
{
    public const SOURCE_BATCH_NAME = 'Test CACT Cría';
    public const TARGET_PASTURE_NAME = 'Test CACT Recría';
    public const TARGET_PENNED_NAME = 'Test CACT Recría Corral';
    public const OTHER_ACTIVITY_NAME = 'Test CACT Invernada';

    /**
     * Segmentos separados a propósito: letras por un lado, dígitos por el otro.
     *
     * Con "GRO001" el agente leía la O como un cero en tres de cada cinco planillas —una letra
     * O pegada a tres ceros es ambigua incluso impresa en monoespaciada— y cada fila volvía
     * como "no existe la caravana". El guión corta esa ambigüedad sin perder de vista de qué
     * grupo es el animal.
     */
    public const TAG_PREFIX = 'GRO-001-CAR-';
    private const SOURCE_ANIMALS = 26;

    /** Fixed, not generated: the scanned images print it and must keep resolving. */
    public const ORDER_CODE = 'TR-20260615-0001';
    private const ORDER_FIRST_ANIMAL = 17;
    private const ORDER_LAST_ANIMAL = 24;
    private const BASELINE_DATE = '2026-06-15';

    public const AT_CHUTE_ORDER_CODE = 'TR-20260615-0002';
    public const DECLARED_ORDER_CODE = 'TR-20260615-0003';
    public const PER_ANIMAL_AT_CHUTE_ORDER_CODE = 'TR-20260615-0004';

    /** Already 4D: a sheet that writes 2D for it is reading a dentition backwards. */
    private const ADVANCED_TEETH_ANIMAL = 25;

    /** Weighed again after the date of the late-weighing sheet. */
    private const LATE_WEIGHING_ANIMAL = 26;
    public const LATE_WEIGHING_DATE = '2026-09-15';
    private const LATE_WEIGHT = 318.0;

    /**
     * The batches the sheets create when loaded. Never seeded; deleted on every run so the
     * "does not exist yet" sheets keep meaning it.
     */
    private const SHEET_CREATED_BATCHES = [
        'Recría Test Nueva 2026',
        'Recría Test Corral Nuevo',
        'Invernada Test Nueva',
    ];

    public function run(): void
    {
        $company = Company::first();

        if (!$company) {
            $this->command?->warn('Cact01ActivityChangeScanTestSeeder: no hay compañía cargada. Se omite.');

            return;
        }

        $companyId = (int) $company->id;
        $calfCategoryId = AnimalCategory::withoutGlobalScopes()->where('code', 'TERNERO')->value('id');

        $source = $this->resolveBatch(
            $companyId,
            self::SOURCE_BATCH_NAME,
            'CRIA',
            false,
            'Origen de las planillas CACT-01 que se escanean en las pruebas del agente de IA.'
        );

        $pasture = $this->resolveBatch(
            $companyId,
            self::TARGET_PASTURE_NAME,
            'RECRIA',
            false,
            'Destino existente a pastura. La celda M escrita como P coincide con este lote.'
        );

        $penned = $this->resolveBatch(
            $companyId,
            self::TARGET_PENNED_NAME,
            'RECRIA',
            true,
            'Destino existente a corral. Sirve para que una celda M escrita como P lo contradiga y avise.'
        );

        $otherActivity = $this->resolveBatch(
            $companyId,
            self::OTHER_ACTIVITY_NAME,
            'INVERNADA',
            true,
            'Existe en otra etapa productiva a propósito: una planilla que declare Recría y lo escriba se rechaza.'
        );

        // Pesos de 208 a 308 kg y dentición que cambia a mitad de la tanda: así una planilla
        // puede mostrar crecimiento real contra una línea de base conocida, y otra puede
        // escribir una dentición que retrocede para que avise.
        $caravanIds = [];

        for ($n = 1; $n <= self::SOURCE_ANIMALS; $n++) {
            $caravanIds[] = $this->seedAnimal(
                $companyId,
                sprintf('%s%03d', self::TAG_PREFIX, $n),
                (int) $source->id,
                $n % 3 === 0 ? 'H' : 'M',
                match (true) {
                    $n === self::ADVANCED_TEETH_ANIMAL => 4,
                    $n <= 16 => 0,
                    default => 2,
                },
                204.0 + ($n * 4),
                $calfCategoryId
            );
        }

        $this->seedLateWeighing($companyId);
        $this->resetScenarioHistory(
            $companyId,
            $caravanIds,
            [(int) $source->id, (int) $pasture->id, (int) $penned->id, (int) $otherActivity->id]
        );

        $this->seedOrder($companyId, (int) $source->id, $pasture, self::ORDER_CODE, range(self::ORDER_FIRST_ANIMAL, self::ORDER_LAST_ANIMAL), 'KEEP');
        $this->seedOrder($companyId, (int) $source->id, $pasture, self::AT_CHUTE_ORDER_CODE, range(7, 10), 'AT_CHUTE');
        $this->seedOrder($companyId, (int) $source->id, $pasture, self::DECLARED_ORDER_CODE, range(11, 16), 'DECLARED', $this->declaredTargets());
        $this->seedOrder($companyId, (int) $source->id, $pasture, self::PER_ANIMAL_AT_CHUTE_ORDER_CODE, range(1, 6), 'AT_CHUTE', perAnimal: true);
        $this->refreshBatchCaches([(int) $source->id, (int) $pasture->id, (int) $penned->id, (int) $otherActivity->id]);

        $this->command?->info(sprintf(
            'Cact01ActivityChangeScanTestSeeder: %d animales (%s001..%03d) en "%s", más los destinos de Recría (pastura y corral) y el de Invernada. Órdenes %s (categoría no cambia), %s (en la manga), %s (declarada) y %s (por animal, en la manga) en EMITIDA.',
            self::SOURCE_ANIMALS,
            self::TAG_PREFIX,
            self::SOURCE_ANIMALS,
            $source->name,
            self::ORDER_CODE,
            self::AT_CHUTE_ORDER_CODE,
            self::DECLARED_ORDER_CODE,
            self::PER_ANIMAL_AT_CHUTE_ORDER_CODE
        ));
    }

    /**
     * The target of each animal of the DECLARED order: Novillito for the males, Vaquillona /
     * Reposición for the females (every third animal is a female, as seeded).
     *
     * @return array<int, array{0: int, 1: ?int}> animal number => [category id, subcategory id]
     */
    private function declaredTargets(): array
    {
        $novillito = (int) AnimalCategory::withoutGlobalScopes()->where('code', 'NOVILLITO')->value('id');
        $vaquillona = (int) AnimalCategory::withoutGlobalScopes()->where('code', 'VAQUILLONA')->value('id');
        $reposicion = AnimalSubcategory::withoutGlobalScopes()
            ->where('category_id', $vaquillona)
            ->where('code', 'REPOSICION')
            ->value('id');

        $targets = [];
        foreach (range(11, 16) as $n) {
            $targets[$n] = $n % 3 === 0 ? [$vaquillona, $reposicion !== null ? (int) $reposicion : null] : [$novillito, null];
        }

        return $targets;
    }

    /**
     * An ISSUED order the order-bearing sheets resolve against, reset to its starting point on
     * every run: roll PENDING, no resolved batch, one history line.
     *
     * @param list<int> $animalNumbers
     * @param array<int, array{0: int, 1: ?int}> $targets animal number => target category, DECLARED only
     * @param bool $perAnimal destination decided at the chute for every animal: no destination row,
     *                        every line of the roll without a batch
     */
    private function seedOrder(
        int $companyId,
        int $sourceBatchId,
        Batch $target,
        string $code,
        array $animalNumbers,
        string $categoryMode,
        array $targets = [],
        bool $perAnimal = false
    ): void {
        $order = TransferOrder::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'code' => $code],
            [
                'source_batch_id' => $sourceBatchId,
                'destination_activity_id' => $target->activity_id,
                'status' => 'ISSUED',
                'destination_mode' => $perAnimal ? 'per_animal' : 'single',
                'category_mode' => $categoryMode,
                'planned_head_count' => count($animalNumbers),
                'movement_date' => self::BASELINE_DATE,
                'emitted_at' => self::BASELINE_DATE . ' 08:00:00',
                'printed_at' => self::BASELINE_DATE . ' 08:05:00',
                'first_executed_at' => null,
                'closed_at' => null,
                'closing_reason' => null,
                'responsable' => 'Encargado de prueba',
                'observations' => 'Orden de prueba de las planillas CACT-01 escaneadas.',
            ]
        );

        TransferOrderAnimal::withoutGlobalScopes()->where('transfer_order_id', $order->id)->delete();
        TransferOrderDestination::withoutGlobalScopes()->where('transfer_order_id', $order->id)->delete();
        $order->history()->withoutGlobalScopes()->delete();

        $destination = $perAnimal ? null : TransferOrderDestination::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'transfer_order_id' => $order->id,
            'destination_key' => 'TEST CACT RECRIA',
            'label' => $target->name,
            'target_batch_id' => $target->id,
        ]);

        foreach ($animalNumbers as $n) {
            $caravanId = Caravan::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('identification', sprintf('%s%03d', self::TAG_PREFIX, $n))
                ->value('id');

            TransferOrderAnimal::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'transfer_order_id' => $order->id,
                'caravan_id' => $caravanId,
                'transfer_order_destination_id' => $destination?->id,
                'target_category_id' => $targets[$n][0] ?? null,
                'target_subcategory_id' => $targets[$n][1] ?? null,
                'status' => 'PENDING',
            ]);
        }

        $order->history()->create([
            'company_id' => $companyId,
            'from_status' => null,
            'to_status' => 'ISSUED',
            'action_reason' => 'Orden sembrada para las pruebas de escaneo CACT-01',
        ]);
    }

    /**
     * The animal of the late-weighing sheet: its baseline stops being current and a later
     * weighing takes its place, dated after the sheet that is going to be scanned.
     */
    private function seedLateWeighing(int $companyId): void
    {
        $caravanId = (int) Caravan::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('identification', sprintf('%s%03d', self::TAG_PREFIX, self::LATE_WEIGHING_ANIMAL))
            ->value('id');

        CaravanWeight::where('caravan_id', $caravanId)->update(['current' => false]);

        CaravanWeight::create([
            'caravan_id' => $caravanId,
            'weight' => self::LATE_WEIGHT,
            'current' => true,
            'weighing_date' => self::LATE_WEIGHING_DATE,
            'notes' => 'Pesada posterior a la planilla de pesaje tardío (pruebas de escaneo CACT-01).',
        ]);
    }

    /**
     * What previous loads left behind: the movements of the scenario animals, the weight curve of
     * its batches, and the batches the sheets created. The batches go only when nothing holds
     * them any more — an order built on one of them from /transfer is left alone and reported.
     *
     * @param list<int> $caravanIds
     * @param list<int> $batchIds
     */
    private function resetScenarioHistory(int $companyId, array $caravanIds, array $batchIds): void
    {
        CaravanMovement::withoutGlobalScopes()->whereIn('caravan_id', $caravanIds)->delete();
        BatchWeight::whereIn('batch_id', $batchIds)->delete();

        // Every sheet loaded without an order leaves a REGISTERED one behind, pointing at the
        // batches it created. They go first, or those batches could never be deleted. PLANNED
        // orders built by hand from /transfer are left alone.
        TransferOrder::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('source_batch_id', $batchIds)
            ->where('kind', 'REGISTERED')
            ->delete();

        $created = Batch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('name', self::SHEET_CREATED_BATCHES)
            ->get();

        foreach ($created as $batch) {
            $held = Caravan::withoutGlobalScopes()->where('batch_id', $batch->id)->exists()
                || TransferOrder::withoutGlobalScopes()->where('source_batch_id', $batch->id)->exists()
                || TransferOrderDestination::withoutGlobalScopes()->where('target_batch_id', $batch->id)->exists();

            if ($held) {
                $this->command?->warn("Cact01ActivityChangeScanTestSeeder: el lote '{$batch->name}' tiene animales u órdenes; no se borra.");

                continue;
            }

            $batch->delete();
        }
    }

    /**
     * batches.caravans_count and the weight figures are a cache that only a recalculation
     * refreshes. Putting the animals back without it leaves the screens announcing the head
     * count of the last load ("Test CACT Cría (20 cab.)" with 26 inside). Written directly,
     * without a curve point: the curve was just emptied on purpose.
     *
     * @param list<int> $batchIds
     */
    private function refreshBatchCaches(array $batchIds): void
    {
        foreach ($batchIds as $batchId) {
            $stats = DB::table('caravans')
                ->leftJoin('caravan_weights', function ($join) {
                    $join->on('caravan_weights.caravan_id', '=', 'caravans.id')->where('caravan_weights.current', true);
                })
                ->where('caravans.batch_id', $batchId)
                ->selectRaw('COUNT(caravans.id) AS heads, COUNT(caravan_weights.id) AS weighed, SUM(caravan_weights.weight) AS total, MIN(caravan_weights.weight) AS min_w, MAX(caravan_weights.weight) AS max_w')
                ->first();

            Batch::withoutGlobalScopes()->whereKey($batchId)->update([
                'caravans_count' => (int) $stats->heads,
                'weighed_count' => (int) $stats->weighed,
                'total_weight' => (float) ($stats->total ?? 0.0),
                'current_weight' => $stats->weighed > 0 ? round((float) $stats->total / (int) $stats->weighed, 2) : null,
                'min_weight' => $stats->min_w,
                'max_weight' => $stats->max_w,
            ]);
        }
    }

    private function resolveBatch(int $companyId, string $name, string $activityCode, ?bool $isConfined, string $observaciones): Batch
    {
        $activity = Activity::withoutGlobalScopes()->where('code', $activityCode)->first();
        $operational = BatchType::withoutGlobalScopes()->where('code', 'OPERATIONAL')->first();

        return Batch::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'name' => $name],
            [
                'farm_id' => null,
                'activity_id' => $activity?->id,
                'batch_type_id' => $operational?->id,
                'is_confined' => $isConfined,
                'is_active' => true,
                'is_system' => false,
                'observaciones' => $observaciones,
            ]
        );
    }

    private function seedAnimal(
        int $companyId,
        string $identification,
        int $batchId,
        string $sex,
        int $teeth,
        float $weight,
        ?int $categoryId
    ): int {
        $caravan = Caravan::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'identification' => $identification],
            // Back to Ternero with no subcategory: the C/S sheets reclassify these animals.
            ['batch_id' => $batchId, 'sex' => $sex, 'teeth' => $teeth, 'category_id' => $categoryId, 'subcategory_id' => null]
        );

        // Un solo peso base, vigente. Al recorrer el seeder se reescribe en vez de apilar
        // historia, así una planilla cargada dos veces siempre mide contra el mismo punto.
        CaravanWeight::where('caravan_id', $caravan->id)->delete();

        CaravanWeight::create([
            'caravan_id' => $caravan->id,
            'weight' => $weight,
            'current' => true,
            'weighing_date' => self::BASELINE_DATE,
            'notes' => 'Peso base de las pruebas de escaneo CACT-01.',
        ]);

        return (int) $caravan->id;
    }
}
