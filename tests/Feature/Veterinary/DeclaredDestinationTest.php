<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\User;
use App\Models\Veterinarian;
use Illuminate\Support\Facades\DB;

/**
 * ADR-39 rev.: el acta que preveía derivar también declara A DÓNDE, y lo declara al despachar.
 *
 * El destino no se pregunta en la manga: ahí muchas veces todavía no está decidido, y para eso
 * existe UNDECIDED. Se pregunta cuando el profesional arma la caja, que es el momento en que lo
 * tiene delante, y desde ahí queda asentado en el acta para precargar el viaje siguiente.
 *
 * El test 3 es el que fija el límite con ADR-38: esta columna se escribe SIEMPRE después de la
 * firma — sólo se despachan tubos de actas firmadas — y por eso no forma parte de lo que la firma
 * congela. Si alguien la agrega alguna vez a upsertActDetail(), ese test se cae.
 */
class DeclaredDestinationTest extends VeterinaryTestCase
{
    private Veterinarian $vet;
    private User $vetUser;

    private const CENTRE = [
        'nombre' => 'Centro de Salud Animal Azul',
        'cuit' => '30712345671',
    ];

    private const LAB_NORTE = [
        'nombre' => 'Laboratorio Norte',
        'cuit' => '30707777779',
        'direccion' => 'Av. Colón 1200, Tandil',
    ];

    private const LAB_SUR = [
        'nombre' => 'Laboratorio Sur',
        'cuit' => '30688888887',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->vet = $this->veterinarian('MP 4582');
        $this->vetUser = User::where('email', 'faranguren@ganadero.com')->firstOrFail();
    }

    public function test_a_shipment_records_its_destination_on_the_derived_acts_it_covers(): void
    {
        [$actId, $tubes] = $this->signedActWithTubes('TO_BE_DERIVED');

        $this->dispatchBox($tubes, self::LAB_NORTE)->assertStatus(201);

        $destination = $this->declaredDestination($actId);

        $this->assertSame(self::LAB_NORTE['nombre'], $destination['nombre']);
        $this->assertSame(self::LAB_NORTE['cuit'], $destination['cuit']);
        $this->assertSame(self::LAB_NORTE['direccion'], $destination['direccion']);

        // Y se lee por la API, al lado del plan que lo anticipaba.
        $act = $this->apiAs('GET', "/diagnostic-protocols/{$actId}");
        $act->assertStatus(200);
        $act->assertJsonPath('data.destination_plan', 'TO_BE_DERIVED');
        $act->assertJsonPath('data.destination_institution.nombre', self::LAB_NORTE['nombre']);

        // El remitente sigue siendo el remitente: la columna nueva no lo tocó.
        $act->assertJsonPath('data.act_institution.nombre', self::CENTRE['nombre']);
    }

    public function test_a_shipment_leaves_in_situ_and_undecided_acts_alone(): void
    {
        // ADR-36: una sola caja, tubos de tres actas con tres planes distintos.
        [$derivedId, $derivedTubes] = $this->signedActWithTubes('TO_BE_DERIVED');
        [$inSituId, $inSituTubes] = $this->signedActWithTubes('IN_SITU');
        [$undecidedId, $undecidedTubes] = $this->signedActWithTubes('UNDECIDED');

        $this->dispatchBox(
            array_merge($derivedTubes, $inSituTubes, $undecidedTubes),
            self::LAB_NORTE
        )->assertStatus(201);

        $this->assertSame(self::LAB_NORTE['nombre'], $this->declaredDestination($derivedId)['nombre']);

        // ADR-40: su plan fue el que fue. El hecho del viaje ya quedó en el envío; escribirle un
        // destino al acta sería contradecir por la espalda lo que se declaró en la manga.
        $this->assertNull($this->rawDestination($inSituId));
        $this->assertNull($this->rawDestination($undecidedId));
    }

    public function test_the_declared_destination_is_writable_after_the_signature(): void
    {
        // El hermano explícito de test_the_plan_is_frozen_by_the_signature: lo que la firma
        // congela sigue congelado, y esta columna no está entre esas cosas.
        [$actId, $tubes] = $this->signedActWithTubes('TO_BE_DERIVED');

        $before = DB::table('extraction_act_details')->where('protocol_id', $actId)->first();

        $this->dispatchBox($tubes, self::LAB_NORTE)->assertStatus(201);

        $after = DB::table('extraction_act_details')->where('protocol_id', $actId)->first();

        $this->assertSame($before->institution, $after->institution);
        $this->assertSame($before->destination_plan, $after->destination_plan);
        $this->assertSame($before->dispatch_note_number, $after->dispatch_note_number);
        $this->assertSame($before->dispatched_at, $after->dispatched_at);

        $this->assertNull($before->destination_institution);
        $this->assertNotNull($after->destination_institution);
    }

    public function test_the_last_shipment_wins(): void
    {
        [$actId, $tubes] = $this->signedActWithTubes('TO_BE_DERIVED');
        $this->assertGreaterThanOrEqual(2, count($tubes), 'El caso necesita dos tubos.');

        // Una jornada partida en dos viajes, y el segundo va a otro laboratorio.
        $this->dispatchBox([$tubes[0]], self::LAB_NORTE)->assertStatus(201);
        $this->assertSame(self::LAB_NORTE['nombre'], $this->declaredDestination($actId)['nombre']);

        $this->dispatchBox([$tubes[1]], self::LAB_SUR)->assertStatus(201);
        $this->assertSame(self::LAB_SUR['nombre'], $this->declaredDestination($actId)['nombre']);

        // El histórico no se perdió: vive en los envíos, uno por caja.
        $this->assertSame(2, DB::table('sample_shipments')->count());
    }

    public function test_correcting_a_shipment_moves_the_declared_destination_too(): void
    {
        [$actId, $tubes] = $this->signedActWithTubes('TO_BE_DERIVED');

        $shipment = $this->dispatchBox($tubes, self::LAB_NORTE);
        $shipment->assertStatus(201);
        $shipmentId = (int) $shipment->json('data.id');

        // ADR-37: se escribió mal el laboratorio y todavía nadie citó la caja. La corrección
        // viaja con el envío entero — el endpoint comparte el request del alta.
        $this->asVet('PATCH', "/veterinary-portal/shipments/{$shipmentId}", [
            'shipped_on' => now()->toDateString(),
            'cold_chain_ok' => true,
            'institution' => self::LAB_SUR,
            'sample_ids' => $tubes,
        ])->assertStatus(200);

        // Si el acta no siguiera al envío, quedaría nombrando un laboratorio que su propio envío
        // ya no menciona.
        $this->assertSame(self::LAB_SUR['nombre'], $this->declaredDestination($actId)['nombre']);
    }

    public function test_voiding_a_shipment_leaves_the_declared_destination_in_place(): void
    {
        [$actId, $tubes] = $this->signedActWithTubes('TO_BE_DERIVED');

        $shipment = $this->dispatchBox($tubes, self::LAB_NORTE);
        $shipment->assertStatus(201);

        $this->asVet('POST', "/veterinary-portal/shipments/{$shipment->json('data.id')}/void", [
            'reason' => 'La conservadora volvió: el laboratorio estaba cerrado.',
        ])->assertStatus(200);

        // La declaración existió. Borrarla dejaría el formulario vacío justo en el reintento.
        $this->assertSame(self::LAB_NORTE['nombre'], $this->declaredDestination($actId)['nombre']);
    }

    public function test_a_malformed_cuit_is_stored_and_never_blocks_the_dispatch(): void
    {
        // ADR-43: el CUIT es descriptivo. Se avisa, se guarda y se sigue.
        [$actId, $tubes] = $this->signedActWithTubes('TO_BE_DERIVED');

        $this->dispatchBox($tubes, [
            'nombre' => 'Laboratorio del Oeste',
            'cuit' => '30111111111',
        ])->assertStatus(201);

        $destination = $this->declaredDestination($actId);

        $this->assertSame('Laboratorio del Oeste', $destination['nombre']);
        $this->assertSame('30111111111', $destination['cuit']);
        $this->assertFalse($destination['cuit_valido']);
    }

    public function test_acts_with_no_declared_destination_read_as_null(): void
    {
        // Regresión de datos históricos: toda acta anterior a esta columna la tiene vacía.
        [$actId] = $this->signedActWithTubes('TO_BE_DERIVED');

        $act = $this->apiAs('GET', "/diagnostic-protocols/{$actId}");

        $act->assertStatus(200);
        $act->assertJsonPath('data.destination_institution', null);
    }

    public function test_pending_tubes_carry_the_declared_destination_for_the_dialog(): void
    {
        // El modal se abre desde /envios/nuevo sin acta objetivo, así que el destino tiene que
        // viajar con cada tubo pendiente o no hay con qué precargar.
        [, $tubes] = $this->signedActWithTubes('TO_BE_DERIVED');
        $this->assertGreaterThanOrEqual(2, count($tubes), 'El caso necesita dos tubos.');

        $this->dispatchBox([$tubes[0]], self::LAB_NORTE)->assertStatus(201);

        $pending = $this->asVet('GET', '/veterinary-portal/pending-tubes');
        $pending->assertStatus(200);

        // Buscado por id y no por posición: la bandeja lista los tubos de todas las actas del
        // profesional (ADR-36), y el orden lo manda la fecha de extracción.
        $entry = collect($pending->json('data'))->firstWhere('id', $tubes[1]);

        $this->assertNotNull($entry, 'El tubo que quedó en mano debería seguir pendiente.');
        $this->assertSame('TO_BE_DERIVED', $entry['destination_plan']);
        $this->assertSame(self::LAB_NORTE['nombre'], $entry['destination_institution']['nombre']);
        $this->assertSame(self::LAB_NORTE['cuit'], $entry['destination_institution']['cuit']);
    }

    /**
     * @param list<int> $tubes
     * @param array<string, mixed> $institution
     * @return \Illuminate\Testing\TestResponse
     */
    private function dispatchBox(array $tubes, array $institution)
    {
        return $this->asVet('POST', '/veterinary-portal/shipments', [
            'shipped_on' => now()->toDateString(),
            'cold_chain_ok' => true,
            'institution' => $institution,
            'sample_ids' => $tubes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function declaredDestination(int $actId): array
    {
        $raw = $this->rawDestination($actId);

        $this->assertNotNull($raw, "El acta {$actId} no tiene destino declarado.");

        return json_decode((string) $raw, true);
    }

    private function rawDestination(int $actId): ?string
    {
        $value = DB::table('extraction_act_details')
            ->where('protocol_id', $actId)
            ->value('destination_institution');

        return $value === null ? null : (string) $value;
    }

    /**
     * @return array{0: int, 1: list<int>}
     */
    private function signedActWithTubes(string $plan): array
    {
        $bulls = $this->bulls(2);

        $response = $this->apiAs('POST', '/pre-service/evaluation-sheets', [
            'veterinarian_id' => $this->vet->id,
            'evaluation_date' => now()->toDateString(),
            'sample_round' => 1,
            'institution' => self::CENTRE,
            'destination_plan' => $plan,
            'bulls' => array_map(static fn ($bull): array => [
                'caravan_id' => $bull->id,
                'blood_serology' => true,
                'blood_serology_tube' => 'S-' . $bull->id . '-' . uniqid(),
            ], $bulls),
        ]);

        $response->assertStatus(201);
        $actId = (int) $response->json('data.id');

        $this->asVet('POST', "/veterinary-portal/acts/{$actId}/sign", [])->assertStatus(200);

        $tubes = DB::table('bull_lab_samples')
            ->where('extraction_act_id', $actId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return [$actId, $tubes];
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function asVet(string $method, string $path, array $payload = [])
    {
        return $this->apiAsUser($this->vetUser, $method, $path, $payload);
    }
}
