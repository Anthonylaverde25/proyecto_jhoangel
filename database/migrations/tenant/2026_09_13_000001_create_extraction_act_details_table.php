<?php

declare(strict_types=1);

use App\Core\Enums\DiagnosticProtocolType;
use App\Core\Enums\SampleDestinationPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-39 / ADR-42 — Migración U: the extraction act declares its institution and its plan.
 *
 * Until now the first institution named anywhere in the chain appeared on the laboratory report,
 * days after the chute. That is the wrong moment: the professional knows which centre they are
 * working with while the animals are in front of them, and had nowhere to write it.
 *
 * The table is created empty and existing acts are seeded with UNDECIDED, which is the truth —
 * nobody declared anything for them. No existing read touches these columns, so this migration
 * stands on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extraction_act_details', function (Blueprint $table): void {
            // 1:1, so the primary key IS the foreign key: two detail rows are unrepresentable.
            $table->unsignedBigInteger('protocol_id')->primary();
            $table->foreign('protocol_id')
                ->references('id')->on('diagnostic_protocols')
                ->cascadeOnDelete();

            // ADR-39: optional. A field sampling may have no institution behind it.
            $table->json('institution')->nullable();

            // ADR-40: an intention. Nothing validates against it.
            $table->string('destination_plan', 20)
                ->default(SampleDestinationPlan::UNDECIDED->value);

            // The remito. Act-only data that was living on the shared header; the values are
            // carried over here, and migration V drops the header columns once nothing reads them.
            $table->string('dispatch_note_number', 50)->nullable();
            $table->date('dispatched_at')->nullable();

            $table->timestamps();

            $table->index('destination_plan');
        });

        // Every act that predates the field declared nothing, and that is what gets recorded.
        // The remito it already had comes along, so no act loses data on the way.
        //
        // Written through the query builder rather than raw SQL: the suite runs on SQLite and
        // production on MySQL, and NOW() only exists in one of them.
        DB::table('extraction_act_details')->insertUsing(
            ['protocol_id', 'institution', 'destination_plan', 'dispatch_note_number', 'dispatched_at', 'created_at', 'updated_at'],
            DB::table('diagnostic_protocols')
                ->where('protocol_type', DiagnosticProtocolType::EXTRACTION_ACT->value)
                ->select([
                    'id',
                    DB::raw('NULL'),
                    DB::raw("'" . SampleDestinationPlan::UNDECIDED->value . "'"),
                    'dispatch_note_number',
                    'dispatched_at',
                    DB::raw('CURRENT_TIMESTAMP'),
                    DB::raw('CURRENT_TIMESTAMP'),
                ])
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('extraction_act_details');
    }
};
