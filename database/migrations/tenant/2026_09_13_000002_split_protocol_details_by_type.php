<?php

declare(strict_types=1);

use App\Core\Enums\DiagnosticProtocolType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-42 — Migración V: the laboratory report's own data moves to its own table.
 *
 * `diagnostic_protocols` keeps what both document types share — identity, numbering, dates,
 * signature, state, voiding — and each type's own data lives beside it.
 *
 * On the NOT NULL: it guarantees that a detail row which EXISTS names its institution, so there is
 * no such thing as a half-filled one. It does NOT say every report has a row, because the
 * digitised historical reports genuinely name no institution and inventing one would be worse than
 * admitting it. The rule that every NEW report must name its institution stays in the use case,
 * which is where a rule about new documents belongs.
 *
 * `result_date` deliberately stays on the header: it drives the COALESCE ordering that keeps acts
 * and reports on a single timeline, plus the from/to filters.
 *
 * Order matters, and v9 taught this the hard way with `drop_health_centers` running before its
 * foreign keys were released: create, copy, VERIFY, and only then drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_report_details', function (Blueprint $table): void {
            $table->unsignedBigInteger('protocol_id')->primary();
            $table->foreign('protocol_id')
                ->references('id')->on('diagnostic_protocols')
                ->cascadeOnDelete();

            // ADR-29: the centre the report is filed from. The guarantee this table exists for.
            $table->json('reporting_institution');

            // ADR-31 (rev.): the third party that ran the assay. Only on a declared derivation.
            $table->json('analysing_institution')->nullable();
            $table->boolean('is_derived')->default(false);

            $table->timestamps();

            $table->index('is_derived');
        });

        $expected = (int) DB::table('diagnostic_protocols')
            ->where('protocol_type', DiagnosticProtocolType::LAB_REPORT->value)
            ->whereNotNull('reporting_institution')
            ->count();

        DB::table('lab_report_details')->insertUsing(
            ['protocol_id', 'reporting_institution', 'analysing_institution', 'is_derived', 'created_at', 'updated_at'],
            DB::table('diagnostic_protocols')
                ->where('protocol_type', DiagnosticProtocolType::LAB_REPORT->value)
                ->whereNotNull('reporting_institution')
                ->select([
                    'id',
                    'reporting_institution',
                    'analysing_institution',
                    'is_derived',
                    DB::raw('CURRENT_TIMESTAMP'),
                    DB::raw('CURRENT_TIMESTAMP'),
                ])
        );

        $copied = (int) DB::table('lab_report_details')->count();

        // Nothing is dropped until the copy is proven. A migration over evidence that cannot be
        // checked is a migration that cannot be trusted.
        if ($copied !== $expected) {
            throw new RuntimeException(
                "La copia de informes no coincide: se esperaban {$expected} y se copiaron {$copied}. No se borra nada."
            );
        }

        Schema::table('diagnostic_protocols', function (Blueprint $table): void {
            $table->dropIndex('dp_company_derived_idx');
            $table->dropColumn([
                'reporting_institution',
                'analysing_institution',
                'is_derived',
                'dispatch_note_number',
                'dispatched_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('diagnostic_protocols', function (Blueprint $table): void {
            $table->json('reporting_institution')->nullable()->after('signed_veterinarian_name');
            $table->json('analysing_institution')->nullable()->after('reporting_institution');
            $table->boolean('is_derived')->default(false)->after('analysing_institution');
            $table->string('dispatch_note_number', 50)->nullable()->after('parent_protocol_id');
            $table->date('dispatched_at')->nullable()->after('dispatch_note_number');

            $table->index(['company_id', 'is_derived'], 'dp_company_derived_idx');
        });

        foreach (DB::table('lab_report_details')->get() as $detail) {
            DB::table('diagnostic_protocols')->where('id', $detail->protocol_id)->update([
                'reporting_institution' => $detail->reporting_institution,
                'analysing_institution' => $detail->analysing_institution,
                'is_derived' => $detail->is_derived,
            ]);
        }

        // The remito lives in the act's detail table, which migration U created and still owns.
        foreach (DB::table('extraction_act_details')->get() as $detail) {
            DB::table('diagnostic_protocols')->where('id', $detail->protocol_id)->update([
                'dispatch_note_number' => $detail->dispatch_note_number,
                'dispatched_at' => $detail->dispatched_at,
            ]);
        }

        Schema::dropIfExists('lab_report_details');
    }
};
