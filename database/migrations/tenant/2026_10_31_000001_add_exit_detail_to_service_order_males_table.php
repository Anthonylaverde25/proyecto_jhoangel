<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Why a bull left a service, as declared facts: a replacement kept them in
 * service_order_bull_replacements, a retirement without replacement only in free text.
 * A bull completed by the torada withdrawal keeps them null (status COMPLETED says it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_order_males', function (Blueprint $table) {
            // BullReplacementReason of a replacement or a retirement without replacement.
            $table->string('exit_reason', 30)->nullable()->after('retired_at');
            $table->text('exit_notes')->nullable()->after('exit_reason');
            // Batch the bull was moved to; null for a death or when he stayed where he was.
            $table->foreignId('exit_destination_batch_id')->nullable()->after('exit_notes')
                ->constrained('batches')->nullOnDelete();
        });

        // Backfill the replaced bulls from their replacement record; a retirement without
        // replacement has no structured record, so it stays null ("motivo no registrado").
        $fromReplacement = static fn (string $column): string => '(SELECT sobr.' . $column . ' FROM service_order_bull_replacements sobr '
            . 'WHERE sobr.service_order_id = service_order_males.service_order_id '
            . 'AND sobr.retired_male_caravan_id = service_order_males.male_caravan_id '
            . 'ORDER BY sobr.replacement_date DESC LIMIT 1)';

        DB::table('service_order_males')->where('status', '!=', 'ACTIVE')->update([
            'exit_reason' => DB::raw($fromReplacement('reason')),
            'exit_notes' => DB::raw($fromReplacement('notes')),
            'exit_destination_batch_id' => DB::raw($fromReplacement('destination_batch_id')),
        ]);
    }

    public function down(): void
    {
        Schema::table('service_order_males', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exit_destination_batch_id');
            $table->dropColumn(['exit_reason', 'exit_notes']);
        });
    }
};
