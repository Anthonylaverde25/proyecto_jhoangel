<?php

declare(strict_types=1);

namespace Tests\Feature\TransferOrders;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\TransferOrder;
use App\Models\TransferOrderAnimal;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * The transfer order: issued on its own button, executed from the screen or from a scanned
 * CACT-01 sheet, possibly in several rounds, and never cancelled once something moved.
 */
class TransferOrderTest extends VeterinaryTestCase
{
    private Batch $source;
    private int $operationalTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Cría Origen TR',
            'activity_id' => $this->activityId('CRIA'),
            'is_active' => true,
        ]);

        $this->operationalTypeId = (int) BatchType::withoutGlobalScopes()->where('code', 'OPERATIONAL')->value('id');
    }

    // ------------------------------------------------------------------ issuing

    public function test_issuing_creates_an_issued_order_with_its_roll_and_a_server_code(): void
    {
        $animals = $this->animals(['TR-A1', 'TR-A2', 'TR-A3']);
        $target = $this->existingBatch('Recría Destino TR');

        $response = $this->emitSingle($animals, ['target_batch_id' => $target->id]);

        $response->assertStatus(201);
        $this->assertMatchesRegularExpression('/^TR-\d{8}-0001$/', $response->json('code'));
        $this->assertSame('ISSUED', $response->json('status'));
        $this->assertSame(3, $response->json('planned_head_count'));
        $this->assertSame(3, $response->json('pending_head_count'));
        $this->assertNull($response->json('printed_at'));
        $this->assertCount(3, $response->json('animals'));
        $this->assertCount(1, $response->json('history'));

        // Nothing moved: issuing is a commitment, not a movement.
        foreach ($animals as $animal) {
            $this->assertSame($this->source->id, (int) $animal->fresh()->batch_id);
        }
    }

    public function test_codes_are_sequential_per_day(): void
    {
        $target = $this->existingBatch('Recría Destino Secuencia');

        $firstOrder = $this->emitSingle($this->animals(['TR-S1']), ['target_batch_id' => $target->id]);
        $first = $firstOrder->json('code');
        $this->assertStringStartsWith('TR-' . now()->format('Ymd') . '-', $first);
        // The batch holds one active order at a time: free it before the second.
        $this->apiAs('POST', '/transfer-orders/' . $firstOrder->json('id') . '/cancel', ['reason' => 'Prueba de secuencia'])->assertStatus(200);
        $second = $this->emitSingle($this->animals(['TR-S2']), ['target_batch_id' => $target->id])->json('code');

        $this->assertSame(substr($first, 0, -4) . '0002', $second);
    }

    public function test_a_batch_with_an_issued_order_refuses_a_second_one(): void
    {
        $animals = $this->animals(['TR-D1', 'TR-D2']);
        $target = $this->existingBatch('Recría Destino Doble');

        $code = $this->emitSingle($animals, ['target_batch_id' => $target->id])->assertStatus(201)->json('code');

        $this->emitSingle($animals, ['target_batch_id' => $target->id], false)
            ->assertStatus(422)
            ->assertJsonPath('code', 'SOURCE_BATCH_HAS_ACTIVE_ORDER')
            ->assertJsonPath('message', "El lote ya tiene la orden {$code} (Emitida). Ejecutala, cerrala o anulala antes de crear otra.");

        $this->assertSame(1, TransferOrder::where('source_batch_id', $this->source->id)->count());
    }

    public function test_an_executed_order_frees_the_batch_for_a_new_one(): void
    {
        $target = $this->existingBatch('Recría Liberada TR');
        $id = $this->emitSingle($this->animals(['TR-F1']), ['target_batch_id' => $target->id])->json('id');

        $this->apiAs('POST', "/transfer-orders/{$id}/execute")->assertStatus(201);

        $this->emitSingle($this->animals(['TR-F2']), ['target_batch_id' => $target->id])->assertStatus(201);
    }

    public function test_a_destination_of_another_activity_is_rejected(): void
    {
        $invernada = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Invernada TR',
            'activity_id' => $this->activityId('INVERNADA'),
            'batch_type_id' => $this->operationalTypeId,
            'is_active' => true,
        ]);

        $this->emitSingle($this->animals(['TR-X1']), ['target_batch_id' => $invernada->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DESTINATION_ACTIVITY_MISMATCH');
    }

    public function test_per_animal_order_may_leave_animals_for_the_chute(): void
    {
        $animals = $this->animals(['TR-P1', 'TR-P2', 'TR-P3']);

        $response = $this->apiAs('POST', '/transfer-orders', [
            'issue' => true,
            'source_batch_id' => $this->source->id,
            'destination_activity_id' => $this->activityId('RECRIA'),
            'destination_mode' => 'per_animal',
            'movement_date' => now()->toDateString(),
            'destinations' => [
                ['key' => 'dest-1', 'label' => 'Recría Nueva TR', 'new_batch_name' => 'Recría Nueva TR', 'new_batch_type_id' => $this->operationalTypeId, 'is_confined' => true],
            ],
            'animals' => [
                ['caravan_id' => $animals[0]->id, 'destination_key' => 'dest-1'],
                ['caravan_id' => $animals[1]->id, 'destination_key' => null],
                ['caravan_id' => $animals[2]->id, 'destination_key' => null],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertSame(2, $response->json('unassigned_head_count'));
        // The destination is keyed by its name, the way the paper will carry it.
        $this->assertSame('RECRIA NUEVA TR', $response->json('destinations.0.key'));

        // Executing from the screen needs a batch for everybody.
        $this->apiAs('POST', '/transfer-orders/' . $response->json('id') . '/execute')
            ->assertStatus(422)
            ->assertJsonPath('code', 'DESTINATION_MISSING');
    }

    // ------------------------------------------------------------------ drafts

    public function test_a_draft_is_the_default_commits_nothing_and_cannot_be_printed_or_executed(): void
    {
        $target = $this->existingBatch('Recría Borrador TR');

        // No `issue` in the payload: the default is a draft.
        $response = $this->apiAs('POST', '/transfer-orders', array_diff_key(
            $this->singlePayload($this->animals(['TR-B1']), ['target_batch_id' => $target->id], true),
            ['issue' => true]
        ));

        $response->assertStatus(201);
        $this->assertSame('DRAFT', $response->json('status'));
        $this->assertTrue($response->json('is_editable'));
        $this->assertFalse($response->json('is_open'));
        $this->assertNull($response->json('emitted_at'));
        $this->assertMatchesRegularExpression('/^TR-\d{8}-\d{4}$/', $response->json('code'), 'El borrador ya tiene código');

        $id = $response->json('id');
        $this->apiAs('POST', "/transfer-orders/{$id}/printed")->assertStatus(422)->assertJsonPath('code', 'DRAFT_NOT_PRINTABLE');
        $this->apiAs('POST', "/transfer-orders/{$id}/execute")->assertStatus(422)->assertJsonPath('code', 'TRANSFER_ORDER_NOT_EXECUTABLE');
    }

    public function test_a_draft_also_holds_the_batch_and_is_issued_later(): void
    {
        $animals = $this->animals(['TR-K1', 'TR-K2']);
        $target = $this->existingBatch('Recría Compartida TR');

        $first = $this->emitSingle($animals, ['target_batch_id' => $target->id], false)->assertStatus(201)->json('id');

        $this->emitSingle($animals, ['target_batch_id' => $target->id], false)
            ->assertStatus(422)
            ->assertJsonPath('code', 'SOURCE_BATCH_HAS_ACTIVE_ORDER');

        $issued = $this->apiAs('POST', "/transfer-orders/{$first}/issue");
        $issued->assertStatus(200);
        $this->assertSame('ISSUED', $issued->json('status'));
        $this->assertNotNull($issued->json('emitted_at'));
    }

    public function test_a_draft_is_rewritten_and_an_issued_order_is_not(): void
    {
        $animals = $this->animals(['TR-U1', 'TR-U2', 'TR-U3']);
        $first = $this->existingBatch('Recría Primera TR');
        $second = $this->existingBatch('Recría Segunda TR');

        $id = $this->emitSingle([$animals[0]], ['target_batch_id' => $first->id], false)->json('id');

        $updated = $this->apiAs('PUT', "/transfer-orders/{$id}", $this->singlePayload($animals, ['target_batch_id' => $second->id], false));

        $updated->assertStatus(200);
        $this->assertSame(3, $updated->json('planned_head_count'));
        $this->assertCount(3, $updated->json('animals'));
        $this->assertSame('Recría Segunda TR', $updated->json('destinations.0.label'));
        $this->assertCount(1, $updated->json('destinations'));

        $this->apiAs('POST', "/transfer-orders/{$id}/issue")->assertStatus(200);

        $this->apiAs('PUT', "/transfer-orders/{$id}", $this->singlePayload([$animals[0]], ['target_batch_id' => $first->id], false))
            ->assertStatus(422)
            ->assertJsonPath('code', 'NOT_EDITABLE');
    }

    public function test_a_draft_is_discarded_without_a_reason(): void
    {
        $target = $this->existingBatch('Recría Descartada TR');
        $id = $this->emitSingle($this->animals(['TR-Z1']), ['target_batch_id' => $target->id], false)->json('id');

        $response = $this->apiAs('POST', "/transfer-orders/{$id}/cancel");

        $response->assertStatus(200);
        $this->assertSame('CANCELLED', $response->json('status'));
        $this->assertNull($response->json('closing_reason'));
    }

    public function test_scanning_the_code_of_a_draft_is_rejected(): void
    {
        $animals = $this->animals(['TR-Y1']);
        $target = $this->existingBatch('Recría Borrador Escaneado TR');
        $id = $this->emitSingle($animals, ['target_batch_id' => $target->id], false)->json('id');

        $response = $this->scan($id, $animals, null, $target->id);

        $response->assertStatus(422);
        $this->assertContains('TRANSFER_ORDER_NOT_EXECUTABLE', array_column($response->json('header_errors'), 'code'));
    }

    // ------------------------------------------------------------------ printing

    public function test_printing_stamps_the_order_once_and_does_not_change_its_status(): void
    {
        $target = $this->existingBatch('Recría Impresa TR');
        $id = $this->emitSingle($this->animals(['TR-I1']), ['target_batch_id' => $target->id])->json('id');

        $first = $this->apiAs('POST', "/transfer-orders/{$id}/printed")->assertStatus(200);
        $this->assertNotNull($first->json('printed_at'));
        $this->assertSame('ISSUED', $first->json('status'));

        $second = $this->apiAs('POST', "/transfer-orders/{$id}/printed")->assertStatus(200);
        $this->assertSame($first->json('printed_at'), $second->json('printed_at'), 'Reimprimir no es un hecho nuevo');
    }

    // ------------------------------------------------------------------ executing

    public function test_executing_from_the_screen_moves_everybody_and_closes_the_order_without_paper(): void
    {
        $animals = $this->animals(['TR-E1', 'TR-E2']);
        $target = $this->existingBatch('Recría Ejecutada TR');
        $id = $this->emitSingle($animals, ['target_batch_id' => $target->id])->json('id');

        $response = $this->apiAs('POST', "/transfer-orders/{$id}/execute");

        $response->assertStatus(201);
        $this->assertSame('EXECUTED', $response->json('data.transfer_order.status'));

        $order = TransferOrder::findOrFail($id);
        $this->assertSame('EXECUTED', $order->status);
        $this->assertNull($order->printed_at);

        foreach ($animals as $animal) {
            $this->assertSame($target->id, (int) $animal->fresh()->batch_id);

            $line = TransferOrderAnimal::where('transfer_order_id', $id)->where('caravan_id', $animal->id)->firstOrFail();
            $this->assertSame('MOVED', $line->status);
            $this->assertSame(
                (int) CaravanMovement::where('caravan_id', $animal->id)->where('type', 'TRANSFER')->value('id'),
                (int) $line->caravan_movement_id
            );
        }
    }

    public function test_a_sheet_in_two_rounds_goes_partial_then_executed_and_reuses_the_batch_it_created(): void
    {
        $animals = $this->animals(['TR-R1', 'TR-R2', 'TR-R3', 'TR-R4']);
        $id = $this->emitSingle($animals, [
            'new_batch_name' => 'Recría Tandas TR',
            'new_batch_type_id' => $this->operationalTypeId,
            'is_confined' => false,
        ])->json('id');

        $first = $this->scan($id, [$animals[0], $animals[1]], 'Recría Tandas TR');

        $first->assertStatus(201);
        $this->assertSame('PARTIAL', $first->json('data.transfer_order.status'));
        $this->assertSame(2, $first->json('data.transfer_order.pending_head_count'));
        $this->assertEqualsCanonicalizing(['TR-R3', 'TR-R4'], $first->json('data.transfer_order.pending_identifications'));
        $this->assertContains('TRANSFER_ORDER_PARTIAL', array_column($first->json('data.warnings'), 'code'));

        // The second sheet names the same new batch: it must land in the one the first created.
        $second = $this->scan($id, [$animals[2], $animals[3]], 'Recría Tandas TR');

        $second->assertStatus(201);
        $this->assertSame('EXECUTED', $second->json('data.transfer_order.status'));
        $this->assertSame(1, Batch::where('name', 'Recría Tandas TR')->count());
        $this->assertFalse($second->json('data.destinations.0.created'));

        // The history says how many each round moved.
        $rounds = TransferOrder::findOrFail($id)->history()->whereNotNull('action_metadata')->pluck('action_metadata')->all();
        $this->assertSame([2, 2], array_column($rounds, 'moved_now'));
    }

    public function test_scanning_an_executed_order_again_says_so_instead_of_blaming_the_batch(): void
    {
        $animals = $this->animals(['TR-T1']);
        $target = $this->existingBatch('Recría Dos Veces TR');
        $id = $this->emitSingle($animals, ['target_batch_id' => $target->id])->json('id');

        $this->scan($id, $animals, null, $target->id)->assertStatus(201);

        $again = $this->scan($id, $animals, null, $target->id);

        $again->assertStatus(422);
        $this->assertContains('TRANSFER_ORDER_NOT_EXECUTABLE', array_column($again->json('header_errors'), 'code'));
        $this->assertSame([], $again->json('row_errors'), 'No se culpa al lote de origen');
    }

    public function test_a_sheet_from_another_batch_cannot_fulfil_the_order(): void
    {
        $animals = $this->animals(['TR-M1']);
        $target = $this->existingBatch('Recría Mismatch TR');
        $id = $this->emitSingle($animals, ['target_batch_id' => $target->id])->json('id');

        $other = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Otro Origen TR',
            'activity_id' => $this->activityId('CRIA'),
            'is_active' => true,
        ]);

        $response = $this->apiAs('POST', '/work-templates/cact-01/process', [
            ...$this->sheetHeader($id),
            'source_batch_id' => $other->id,
            'destinations' => [['key' => 'D', 'target_batch_id' => $target->id, 'new_batch' => null]],
            'rows' => [$this->row('TR-M1', 'D')],
        ]);

        $response->assertStatus(422);
        $this->assertContains('TRANSFER_ORDER_SOURCE_MISMATCH', array_column($response->json('header_errors'), 'code'));
    }

    public function test_an_animal_outside_the_roll_warns_and_still_moves(): void
    {
        $animals = $this->animals(['TR-W1', 'TR-W2']);
        $target = $this->existingBatch('Recría Extra TR');
        $id = $this->emitSingle([$animals[0]], ['target_batch_id' => $target->id])->json('id');

        $response = $this->scan($id, $animals, null, $target->id);

        $response->assertStatus(201);
        $this->assertContains('ANIMAL_NOT_IN_ORDER', array_column($response->json('data.warnings'), 'code'));
        $this->assertSame($target->id, (int) $animals[1]->fresh()->batch_id);
    }

    public function test_a_blank_sheet_gets_its_order_on_confirming(): void
    {
        $animals = $this->animals(['TR-N1', 'TR-N2']);
        $target = $this->existingBatch('Recría Sin Orden TR');

        $response = $this->apiAs('POST', '/work-templates/cact-01/process', [
            ...$this->sheetHeader(null),
            'destinations' => [['key' => 'D', 'target_batch_id' => $target->id, 'new_batch' => null]],
            'rows' => [$this->row('TR-N1', 'D'), $this->row('TR-N2', 'D')],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.transfer_order.status', 'EXECUTED');
        $response->assertJsonPath('data.transfer_order.created_from_sheet', true);
        $response->assertJsonPath('data.transfer_order.planned_head_count', 2);
        $this->assertSame($target->id, (int) $animals[0]->fresh()->batch_id);

        // Born from the paper: registered after the fact, its roll is the rows of the sheet and
        // its only destination is the batch the animals went to.
        $order = TransferOrder::where('source_batch_id', $this->source->id)->sole();
        $this->assertSame('REGISTERED', $order->kind);
        $this->assertSame('single', $order->destination_mode);
        $this->assertSame($target->id, (int) $order->destinations()->sole()->target_batch_id);
    }

    public function test_a_code_that_resolves_to_nothing_is_rejected_instead_of_creating_an_order(): void
    {
        $animals = $this->animals(['TR-N3']);
        $target = $this->existingBatch('Recría Código Inexistente TR');

        $response = $this->apiAs('POST', '/work-templates/cact-01/process', [
            ...$this->sheetHeader(null),
            'orden_transferencia' => 'TR-20260999-0042',
            'destinations' => [['key' => 'D', 'target_batch_id' => $target->id, 'new_batch' => null]],
            'rows' => [$this->row('TR-N3', 'D')],
        ]);

        $response->assertStatus(422);
        $this->assertContains('TRANSFER_ORDER_NOT_FOUND', array_column($response->json('header_errors'), 'code'));
        $this->assertSame(0, TransferOrder::where('source_batch_id', $this->source->id)->count());
        $this->assertSame($this->source->id, (int) $animals[0]->fresh()->batch_id);
    }

    public function test_obtaining_an_order_for_a_sheet_issues_it_without_moving_and_the_sheet_then_executes_it(): void
    {
        $animals = $this->animals(['TR-G1', 'TR-G2']);
        $target = $this->existingBatch('Recría Orden Obtenida TR');
        $sheet = [
            ...$this->sheetHeader(null),
            // What the scanner misread into the code box: obtaining an order ignores it.
            'orden_transferencia' => 'CACT-01',
            'destinations' => [['key' => 'D', 'target_batch_id' => $target->id, 'new_batch' => null]],
            'rows' => [$this->row('TR-G1', 'D'), $this->row('TR-G2', 'D')],
        ];

        $obtained = $this->apiAs('POST', '/work-templates/cact-01/order', $sheet);

        $obtained->assertStatus(201);
        $obtained->assertJsonPath('data.status', 'ISSUED');
        $obtained->assertJsonPath('data.planned_head_count', 2);
        $this->assertSame($this->source->id, (int) $animals[0]->fresh()->batch_id);

        // The review writes the real code into the header and confirms against the order.
        $confirmed = $this->apiAs('POST', '/work-templates/cact-01/process', [
            ...$sheet,
            'orden_transferencia' => $obtained->json('data.code'),
            'transfer_order_id' => $obtained->json('data.id'),
        ]);

        $confirmed->assertStatus(201);
        $confirmed->assertJsonPath('data.transfer_order.status', 'EXECUTED');
        $this->assertSame($target->id, (int) $animals[0]->fresh()->batch_id);
        $this->assertSame(1, TransferOrder::where('source_batch_id', $this->source->id)->count());
    }

    public function test_obtaining_an_order_for_a_sheet_with_errors_creates_nothing(): void
    {
        $this->animals(['TR-G3']);
        $target = $this->existingBatch('Recría Orden Rechazada TR');

        $response = $this->apiAs('POST', '/work-templates/cact-01/order', [
            ...$this->sheetHeader(null),
            'destinations' => [['key' => 'D', 'target_batch_id' => $target->id, 'new_batch' => null]],
            'rows' => [$this->row('TR-G3', 'D'), $this->row('TR-NO-EXISTE', 'D')],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, TransferOrder::where('source_batch_id', $this->source->id)->count());
    }

    // ------------------------------------------------------------------ closing

    public function test_cancelling_requires_a_reason_and_frees_the_animals(): void
    {
        $animals = $this->animals(['TR-C1']);
        $target = $this->existingBatch('Recría Anulada TR');
        $id = $this->emitSingle($animals, ['target_batch_id' => $target->id])->json('id');

        $this->apiAs('POST', "/transfer-orders/{$id}/cancel", ['reason' => ''])->assertStatus(422);

        $response = $this->apiAs('POST', "/transfer-orders/{$id}/cancel", ['reason' => 'Se rearma con otro destino']);

        $response->assertStatus(200);
        $this->assertSame('CANCELLED', $response->json('status'));
        $this->assertSame('Se rearma con otro destino', $response->json('closing_reason'));

        // Rebuilding: the same animals can go into a new order.
        $this->emitSingle($animals, ['target_batch_id' => $target->id])->assertStatus(201);
    }

    public function test_a_partial_order_cannot_be_cancelled_only_closed_incomplete(): void
    {
        $animals = $this->animals(['TR-Q1', 'TR-Q2']);
        $target = $this->existingBatch('Recría Parcial TR');
        $id = $this->emitSingle($animals, ['target_batch_id' => $target->id])->json('id');

        $this->scan($id, [$animals[0]], null, $target->id)->assertStatus(201);

        $this->apiAs('POST', "/transfer-orders/{$id}/cancel", ['reason' => 'No se hizo'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        $closed = $this->apiAs('POST', "/transfer-orders/{$id}/close-incomplete", ['reason' => 'Un animal quedó en el campo']);

        $closed->assertStatus(200);
        $this->assertSame('CLOSED_INCOMPLETE', $closed->json('status'));
        $this->assertSame(1, $closed->json('moved_head_count'));
        $this->assertSame(1, $closed->json('skipped_head_count'));
    }

    // ------------------------------------------------------------------ reading

    public function test_list_filters_by_status_and_the_scan_finds_a_misread_code(): void
    {
        $target = $this->existingBatch('Recría Listado TR');
        // Cancelled first: the batch holds one active order at a time.
        $cancelled = $this->emitSingle($this->animals(['TR-L2']), ['target_batch_id' => $target->id]);
        $this->apiAs('POST', '/transfer-orders/' . $cancelled->json('id') . '/cancel', ['reason' => 'Prueba de listado']);
        $issued = $this->emitSingle($this->animals(['TR-L1']), ['target_batch_id' => $target->id]);

        // Scoped to this batch: the tenant seeders leave an order of their own.
        $batch = '&source_batch_id=' . $this->source->id;
        $this->assertCount(2, $this->apiAs('GET', '/transfer-orders?' . ltrim($batch, '&'))->json());
        $this->assertSame(
            [$issued->json('code')],
            array_column($this->apiAs('GET', '/transfer-orders?status=ISSUED' . $batch)->json(), 'code')
        );

        // An O read where a zero must be is corrected, never guessed around.
        $misread = strtolower(str_replace('0', 'O', $issued->json('code')));
        $this->apiAs('GET', '/transfer-orders/by-code/' . $misread)
            ->assertStatus(200)
            ->assertJsonPath('id', $issued->json('id'));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param list<Caravan> $animals
     * @param array<string, mixed> $destination
     */
    private function emitSingle(array $animals, array $destination, bool $issue = true)
    {
        return $this->apiAs('POST', '/transfer-orders', $this->singlePayload($animals, $destination, $issue));
    }

    /**
     * @param list<Caravan> $animals
     * @param array<string, mixed> $destination
     * @return array<string, mixed>
     */
    private function singlePayload(array $animals, array $destination, bool $issue): array
    {
        return [
            'issue' => $issue,
            'source_batch_id' => $this->source->id,
            'destination_activity_id' => $this->activityId('RECRIA'),
            'destination_mode' => 'single',
            'movement_date' => now()->toDateString(),
            'destinations' => [['key' => 'dest-single', 'label' => '', ...$destination]],
            'animals' => array_map(fn (Caravan $c) => ['caravan_id' => $c->id, 'destination_key' => 'dest-single'], $animals),
        ];
    }

    /**
     * A scanned sheet against the order, to an existing batch or to one written by hand.
     *
     * @param list<Caravan> $animals
     */
    private function scan(int $orderId, array $animals, ?string $newBatchName, ?int $targetBatchId = null)
    {
        $destination = $targetBatchId !== null
            ? ['key' => 'DESTINO', 'target_batch_id' => $targetBatchId, 'new_batch' => null]
            : ['key' => 'DESTINO', 'target_batch_id' => null, 'new_batch' => [
                'name' => $newBatchName,
                'activity_id' => $this->activityId('RECRIA'),
                'batch_type_id' => $this->operationalTypeId,
                'is_confined' => false,
            ]];

        return $this->apiAs('POST', '/work-templates/cact-01/process', [
            ...$this->sheetHeader($orderId),
            'destinations' => [$destination],
            'rows' => array_map(fn (Caravan $c) => $this->row($c->identification, 'DESTINO'), $animals),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sheetHeader(?int $orderId): array
    {
        return [
            'source_batch_id' => $this->source->id,
            'fecha_movimiento' => now()->toDateString(),
            'actividad_destino_id' => $this->activityId('RECRIA'),
            'transfer_order_id' => $orderId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $tag, string $key): array
    {
        return [
            'caravana' => $tag,
            'peso_actual' => null,
            'categoria' => null,
            'dientes' => null,
            'destination_key' => $key,
            'manejo' => null,
            'observations' => null,
        ];
    }

    /**
     * @param list<string> $tags
     * @return list<Caravan>
     */
    private function animals(array $tags): array
    {
        return array_map(fn (string $tag) => Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->source->id,
            'identification' => $tag,
            'sex' => 'M',
            'teeth' => 0,
        ]), $tags);
    }

    private function existingBatch(string $name): Batch
    {
        return Batch::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'activity_id' => $this->activityId('RECRIA'),
            'batch_type_id' => $this->operationalTypeId,
            'is_confined' => false,
            'is_active' => true,
        ]);
    }

    private function activityId(string $code): int
    {
        return (int) Activity::withoutGlobalScopes()->where('code', $code)->value('id');
    }
}
