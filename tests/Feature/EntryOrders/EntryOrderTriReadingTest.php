<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\Caravan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * "Adjuntar TRI": the SENASA TRI of a DTE is read by the AI to fill in the caravans of its
 * reception. Several pages are read in item order; what looks wrong is reported, not fixed.
 */
class EntryOrderTriReadingTest extends EntryOrderTestCase
{
    /**
     * @param list<array{0: int, 1: string}> $rows item, caravan
     * @return array<string, mixed> what the ai-agent answers for one TRI page
     */
    private function page(int $page, array $rows, string $renspa = '01.234.5.67890/00'): array
    {
        return [
            'status' => 'success',
            'identified_template' => ['code' => 'TRI'],
            'context' => ['tri_numero' => ['value' => '0012345678901-2'], 'renspa_origen' => ['value' => $renspa], 'hoja_numero' => ['value' => (string) $page], 'hoja_total' => ['value' => '2']],
            'data' => [['mapped_rows' => array_map(fn ($r) => ['item' => ['value' => (string) $r[0]], 'caravana' => ['value' => $r[1]]], $rows), 'total_detected' => count($rows)]],
        ];
    }

    public function test_the_caravans_of_every_page_are_read_in_order_and_checked(): void
    {
        $order = $this->createOrder()->json('order');
        Caravan::create(['company_id' => $this->company->id, 'identification' => 'TRI-EXISTS', 'sex' => 'M']);

        Http::fakeSequence()
            ->push($this->page(1, [[2, 'tri 002'], [1, 'TRI-001'], [3, '']]))
            ->push($this->page(2, [[26, 'TRI-026'], [27, 'TRI-001'], [28, 'TRI-EXISTS']], '09.999.9.99999/00'));

        $reading = $this->apiAs('POST', "/entry-orders/{$order['id']}/tri", [
            'documents' => [UploadedFile::fake()->image('tri-1.jpg'), UploadedFile::fake()->image('tri-2.jpg')],
        ])->assertOk()->json();

        $this->assertSame(['TRI-001', 'TRI002', 'TRI-026', 'TRI-EXISTS'], array_column($reading['caravans'], 'caravana'));
        $this->assertSame(['TRI-001'], $reading['repeated']);
        $this->assertSame(['TRI-EXISTS'], $reading['existing']);
        $this->assertSame(['0012345678901-2'], $reading['tri_numbers']);
        $this->assertCount(2, $reading['pages']);
        $this->assertStringContainsString('RENSPA', $reading['warnings'][0]);
    }

    public function test_a_pdf_of_several_pages_keeps_the_reading_order(): void
    {
        $order = $this->createOrder()->json('order');

        Http::fake(['*' => Http::response($this->page(1, [[1, 'PDF-1'], [2, 'PDF-2'], [1, 'PDF-3'], [2, 'PDF-4']]))]);

        $reading = $this->apiAs('POST', "/entry-orders/{$order['id']}/tri", [
            'documents' => [UploadedFile::fake()->create('tri.pdf', 50, 'application/pdf')],
        ])->assertOk()->json();

        $this->assertSame(['PDF-1', 'PDF-2', 'PDF-3', 'PDF-4'], array_column($reading['caravans'], 'caravana'));
    }

    public function test_a_page_the_ai_cannot_read_is_reported_and_the_rest_read(): void
    {
        $order = $this->createOrder()->json('order');

        Http::fakeSequence()
            ->push(['detail' => 'Gemini 503'], 503)
            ->push($this->page(2, [[26, 'TRI-026']]));

        $reading = $this->apiAs('POST', "/entry-orders/{$order['id']}/tri", [
            'documents' => [UploadedFile::fake()->image('borrosa.jpg'), UploadedFile::fake()->image('tri-2.jpg')],
        ])->assertOk()->json();

        $this->assertSame(['TRI-026'], array_column($reading['caravans'], 'caravana'));
        $this->assertSame('No se pudo leer el archivo.', $reading['pages'][0]['error']);
    }

    public function test_only_images_and_pdfs_are_accepted(): void
    {
        $order = $this->createOrder()->json('order');

        $this->apiAs('POST', "/entry-orders/{$order['id']}/tri", ['documents' => [UploadedFile::fake()->create('tri.xlsx', 10)]])
            ->assertStatus(422);
    }
}
