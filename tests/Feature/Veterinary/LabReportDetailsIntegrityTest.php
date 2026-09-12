<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-42: the guarantee the split was for.
 *
 * "Every report names the institution it is filed from" used to live only in a FormRequest and a
 * use case, where a seeder, a console command or a direct update walks straight past it. It is now
 * the schema's job — asserted here against the engine, deliberately bypassing every application
 * layer, because a rule tested through the layer that enforces it proves nothing about the table.
 *
 * The scope is honest and narrow: a detail row that EXISTS is complete. It does not say every
 * report has one, because the digitised historical reports genuinely name no institution.
 */
class LabReportDetailsIntegrityTest extends VeterinaryTestCase
{
    public function test_the_database_refuses_a_lab_report_detail_without_its_institution(): void
    {
        $reportId = (int) DB::table('diagnostic_protocols')
            ->where('protocol_type', 'LAB_REPORT')
            ->value('id');

        $this->assertNotSame(0, $reportId, 'El seeder tiene que dejar un informe para este caso.');

        DB::table('lab_report_details')->where('protocol_id', $reportId)->delete();

        $this->expectException(QueryException::class);

        DB::table('lab_report_details')->insert([
            'protocol_id' => $reportId,
            'reporting_institution' => null,
            'is_derived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_detail_row_dies_with_the_protocol_it_describes(): void
    {
        // On a throwaway protocol: a seeded act has tubes and diagnoses hanging off it whose own
        // foreign keys block the delete, and those have nothing to do with the cascade under test.
        $protocolId = (int) DB::table('diagnostic_protocols')->insertGetId([
            'company_id' => (int) $this->company->id,
            'protocol_number' => 'ACTA-CASCADE-' . uniqid(),
            'protocol_type' => 'EXTRACTION_ACT',
            'sample_date' => now()->toDateString(),
            'source_channel' => 'OWNER_DIGITIZED',
            'status' => 'DRAFT',
            'verification_status' => 'UNVERIFIED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('extraction_act_details')->insert([
            'protocol_id' => $protocolId,
            'destination_plan' => 'IN_SITU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('extraction_act_details', ['protocol_id' => $protocolId]);

        DB::table('diagnostic_protocols')->where('id', $protocolId)->delete();

        // The 1:1 is the primary key and the cascade means no detail row outlives its document.
        $this->assertDatabaseMissing('extraction_act_details', ['protocol_id' => $protocolId]);
    }

    public function test_one_protocol_cannot_have_two_detail_rows(): void
    {
        $actId = (int) DB::table('diagnostic_protocols')
            ->where('protocol_type', 'EXTRACTION_ACT')
            ->value('id');

        $this->expectException(QueryException::class);

        DB::table('extraction_act_details')->insert([
            'protocol_id' => $actId,
            'destination_plan' => 'IN_SITU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
