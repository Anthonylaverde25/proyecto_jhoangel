<?php

declare(strict_types=1);

namespace Tests\Feature\Insemination;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\Caravan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Semen\SemenTestCase;

/**
 * AI service orders (semen S-B) over the API: a Cría batch with empty cows, one semen item with two
 * lots in the test tank (L-A 20 straws, L-B 3 straws) and the seeded APT bulls for the repaso.
 */
abstract class InseminationTestCase extends SemenTestCase
{
    protected Batch $rodeo;
    protected Batch $bullPaddock;
    protected int $semenItem;
    protected int $lotA;
    protected int $lotB;
    protected string $start;

    protected function setUp(): void
    {
        parent::setUp();

        $cria = Activity::updateOrCreate(['code' => 'CRIA'], ['name' => 'Cría']);
        $this->rodeo = $this->newBatch('Rodeo IA ' . Str::random(4), $cria->id);
        $this->bullPaddock = $this->newBatch('Toros IA ' . Str::random(4), $cria->id);
        $this->start = now()->subDays(20)->toDateString();

        $sire = $this->sire('Rambo IA ' . Str::random(4));
        $doc = $this->receipt([$this->receiptLine($sire, 'L-A', 20, '10000'), $this->receiptLine($sire, 'L-B', 3, '10000')])
            ->assertStatus(201)->json();
        $this->semenItem = (int) $doc['lines'][0]['item']['id'];
        $this->lotA = $this->lotId($this->semenItem, 'L-A');
        $this->lotB = $this->lotId($this->semenItem, 'L-B');
    }

    protected function newBatch(string $name, int $activityId): Batch
    {
        return Batch::create(['company_id' => $this->company->id, 'name' => $name, 'is_active' => true, 'activity_id' => $activityId]);
    }

    /** @return list<Caravan> */
    protected function cows(int $count, ?Batch $batch = null): array
    {
        $cows = [];
        for ($i = 0; $i < $count; $i++) {
            $cows[] = Caravan::create([
                'company_id' => $this->company->id,
                'batch_id' => ($batch ?? $this->rodeo)->id,
                'identification' => 'IA-' . Str::upper(Str::random(6)),
                'sex' => 'H',
                'teeth' => 4,
            ]);
        }

        return $cows;
    }

    protected function bullIn(string $tag, Batch $batch): Caravan
    {
        $bull = Caravan::withoutGlobalScopes()->where('company_id', $this->company->id)->where('identification', $tag)->firstOrFail();
        $bull->update(['batch_id' => $batch->id]);

        return $bull;
    }

    /**
     * @param list<Caravan> $cows
     * @param array<int, int> $planned cow id => item id
     */
    protected function startAi(array $cows, array $planned = [], ?string $date = null): TestResponse
    {
        return $this->apiAs('POST', "/batches/{$this->rodeo->id}/start-service", [
            'service_method' => 'AI',
            'selected_female_caravan_ids' => array_map(fn (Caravan $c) => $c->id, $cows),
            'planned_start_date' => $date ?? $this->start,
            'planned_semen' => array_map(fn ($cowId, $itemId) => ['female_caravan_id' => $cowId, 'item_id' => $itemId], array_keys($planned), $planned),
        ]);
    }

    /** @return array<string, mixed> */
    protected function row(Caravan $cow, ?int $lot, int $straws = 1, ?string $date = null, ?int $warehouse = null, ?int $item = null): array
    {
        return [
            'female_caravan_id' => $cow->id,
            'inseminated_on' => $date ?? now()->subDays(15)->toDateString(),
            'item_id' => $item ?? $this->semenItem,
            'semen_lot_id' => $lot,
            'straws_used' => $straws,
            'warehouse_id' => $warehouse ?? $this->tank,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    protected function inseminate(int $orderId, array $rows, ?string $key = null): TestResponse
    {
        return $this->apiAs('POST', "/service-orders/{$orderId}/inseminations", [
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'rows' => $rows,
        ]);
    }

    protected function order(int $orderId): array
    {
        return $this->apiAs('GET', "/service-orders/{$orderId}")->assertOk()->json();
    }

    protected function tankBalance(): string
    {
        return bcadd((string) (DB::table('item_stocks')->where('warehouse_id', $this->tank)->where('item_id', $this->semenItem)->value('quantity') ?? '0'), '0', 4);
    }

    protected function tacto(int $orderId, Caravan $cow, bool $pregnant, ?string $date = null): TestResponse
    {
        return $this->apiAs('POST', "/caravans/{$cow->id}/gestation-diagnosis", array_filter([
            'service_order_id' => $orderId,
            'is_pregnant' => $pregnant,
            'gestation_stage' => $pregnant ? 'head' : null,
            'diagnosis_date' => $date ?? now()->toDateString(),
        ], fn ($value) => $value !== null));
    }
}
