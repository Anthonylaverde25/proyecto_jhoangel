<?php

declare(strict_types=1);

namespace Tests\Feature\Caravans;

use App\Core\Interfaces\ICaravanRepository;
use App\Models\Batch;
use App\Models\Caravan;
use Illuminate\Support\Carbon;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * caravans.batch_assigned_at: when the animal joined its current batch, kept on every path that
 * changes the batch, model events or not.
 */
class CaravanBatchAssignedAtTest extends VeterinaryTestCase
{
    private Batch $source;
    private Batch $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = Batch::create(['company_id' => $this->company->id, 'name' => 'Origen BA', 'is_active' => true]);
        $this->target = Batch::create(['company_id' => $this->company->id, 'name' => 'Destino BA', 'is_active' => true]);
        Carbon::setTestNow('2026-10-01 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_caravan_created_in_a_batch_is_stamped(): void
    {
        $caravan = $this->caravan('BA-001', $this->source->id);

        $this->assertSame('2026-10-01 08:00:00', $this->assignedAt($caravan));
        $this->assertNull($this->assignedAt($this->caravan('BA-002', null)));
    }

    public function test_a_model_save_restamps_only_when_the_batch_changes(): void
    {
        $caravan = $this->caravan('BA-010', $this->source->id);

        Carbon::setTestNow('2026-10-05 09:00:00');
        $caravan->update(['teeth' => 4]);
        $this->assertSame('2026-10-01 08:00:00', $this->assignedAt($caravan));

        $caravan->update(['batch_id' => $this->target->id]);
        $this->assertSame('2026-10-05 09:00:00', $this->assignedAt($caravan));

        $caravan->update(['batch_id' => null]);
        $this->assertNull($this->assignedAt($caravan));
    }

    public function test_a_bulk_move_stamps_only_the_caravans_that_change_batch(): void
    {
        $moved = $this->caravan('BA-020', $this->source->id);
        $already = $this->caravan('BA-021', $this->target->id);

        Carbon::setTestNow('2026-10-07 10:00:00');
        app(ICaravanRepository::class)->moveCaravansToBatch([$moved->id, $already->id], $this->target->id);

        $this->assertSame('2026-10-07 10:00:00', $this->assignedAt($moved));
        $this->assertSame('2026-10-01 08:00:00', $this->assignedAt($already));
    }

    public function test_the_single_caravan_query_builder_moves_stamp_the_arrival(): void
    {
        $first = $this->caravan('BA-030', $this->source->id);
        $second = $this->caravan('BA-031', $this->source->id);
        $repository = app(ICaravanRepository::class);

        Carbon::setTestNow('2026-10-08 11:00:00');
        $repository->updateBatchAndCategory($first->id, $this->target->id);
        $repository->updateBatchAndReclassify($second->id, $this->target->id, $this->anyCategoryId());

        $this->assertSame('2026-10-08 11:00:00', $this->assignedAt($first));
        $this->assertSame('2026-10-08 11:00:00', $this->assignedAt($second));

        // Same batch again: not an arrival.
        Carbon::setTestNow('2026-10-09 11:00:00');
        $repository->updateBatchAndCategory($first->id, $this->target->id);
        $this->assertSame('2026-10-08 11:00:00', $this->assignedAt($first));
    }

    private function caravan(string $identification, ?int $batchId): Caravan
    {
        return Caravan::create([
            'company_id' => $this->company->id,
            'identification' => $identification,
            'batch_id' => $batchId,
            'sex' => 'H',
        ]);
    }

    private function assignedAt(Caravan $caravan): ?string
    {
        return $caravan->fresh()->batch_assigned_at?->format('Y-m-d H:i:s');
    }

    private function anyCategoryId(): int
    {
        return (int) \App\Models\AnimalCategory::withoutGlobalScopes()->orderBy('id')->value('id');
    }
}
