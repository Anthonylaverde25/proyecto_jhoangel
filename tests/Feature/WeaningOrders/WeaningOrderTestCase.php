<?php

declare(strict_types=1);

namespace Tests\Feature\WeaningOrders;

use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\AnimalSubcategory;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\CaravanLineage;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * Two breeding batches with calves at foot and one weaning batch: the smallest herd a weaning
 * order has to handle, since one order may take calves of several breeding batches.
 */
abstract class WeaningOrderTestCase extends VeterinaryTestCase
{
    protected Batch $breedingA;
    protected Batch $breedingB;
    protected Batch $weaningBatch;
    protected int $weaningTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->weaningTypeId = (int) BatchType::withoutGlobalScopes()->where('code', 'WEANING')->value('id');

        $this->breedingA = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Rodeo Cría A WO',
            'activity_id' => $this->activityId('CRIA'),
            'is_confined' => false,
            'is_active' => true,
        ]);

        $this->breedingB = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Rodeo Cría B WO',
            'activity_id' => $this->activityId('CRIA'),
            'is_confined' => false,
            'is_active' => true,
        ]);

        $this->weaningBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Destete Existente WO',
            'activity_id' => $this->activityId('CRIA'),
            'batch_type_id' => $this->weaningTypeId,
            'is_confined' => true,
            'is_active' => true,
        ]);
    }

    protected function nursingCalf(string $tag, string $sex, ?Batch $batch = null, ?string $birthDate = null): Caravan
    {
        $batch ??= $this->breedingA;
        $birthDate ??= now()->subMonths(7)->toDateString();

        $mother = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $batch->id,
            'identification' => "{$tag}-M",
            'sex' => 'H',
            'category_id' => $this->categoryId('VACA'),
        ]);
        $gestation = CaravanGestation::create([
            'caravan_id' => $mother->id,
            'start_date' => now()->subMonths(16)->toDateString(),
            'is_current' => false,
            'success' => true,
            'end_date' => $birthDate,
        ]);

        $calf = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $batch->id,
            'identification' => $tag,
            'sex' => $sex,
            'teeth' => 0,
            'category_id' => $this->categoryId('TERNERO'),
        ]);
        CaravanLineage::create([
            'caravan_id' => $calf->id,
            'mother_id' => $mother->id,
            'gestation_id' => $gestation->id,
            'birth_date' => $birthDate,
            'is_nursing' => true,
        ]);

        return $calf;
    }

    /**
     * An order with every calf to the existing weaning batch, issued unless told otherwise.
     *
     * @param Caravan[] $calves
     * @param array<string, mixed> $overrides
     */
    protected function emitSingle(array $calves, array $overrides = [])
    {
        return $this->apiAs('POST', '/weaning-orders', [
            'issue' => true,
            'destination_mode' => 'single',
            'weaning_date' => now()->toDateString(),
            'weaning_type' => 'TRADITIONAL',
            'destinations' => [['key' => 'd1', 'label' => '', 'target_batch_id' => $this->weaningBatch->id]],
            'animals' => array_map(fn (Caravan $c) => ['caravan_id' => $c->id, 'destination_key' => 'd1'], $calves),
            ...$overrides,
        ]);
    }

    /**
     * A scanned DEST-01 sheet naming an order, every calf to the order's single destination.
     *
     * @param list<array{0: Caravan, 1: ?float, 2?: ?string}> $lines calf, weight, C/S cell
     * @param array<string, mixed> $overrides
     */
    protected function scanSingle(?int $orderId, array $lines, array $overrides = [])
    {
        return $this->apiAs('POST', '/work-templates/dest-01/process', [
            'fecha_destete' => now()->toDateString(),
            'weaning_order_id' => $orderId,
            'destination_mode' => 'single',
            'destinations' => [['key' => 'Destete Existente WO', 'target_batch_id' => $this->weaningBatch->id, 'new_batch' => null]],
            'rows' => array_map(fn (array $line) => [
                'caravana' => $line[0]->identification,
                'peso' => $line[1],
                'cs_nueva' => $line[2] ?? null,
            ], $lines),
            ...$overrides,
        ]);
    }

    protected function categoryId(string $code): int
    {
        return (int) AnimalCategory::where('code', $code)->value('id');
    }

    protected function subcategoryId(string $categoryCode, string $code): int
    {
        return (int) AnimalSubcategory::where('category_id', $this->categoryId($categoryCode))->where('code', $code)->value('id');
    }

    protected function activityId(string $code): int
    {
        return (int) Activity::withoutGlobalScopes()->where('code', $code)->value('id');
    }

    protected function isNursing(Caravan $calf): bool
    {
        return (bool) CaravanLineage::where('caravan_id', $calf->id)->value('is_nursing');
    }
}
