<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Head received by count whose caravans are still to write, by DTE and by the batch they went to.
 * A batch counts them as "pending identification" next to its caravans, so it grows the day the
 * troop arrives. The rows of a DTE add up to its uncaravaned_head_count; a row is removed when its
 * last head is identified or written off.
 *
 * The DTE also records the day its head arrived, so caravans written later enter with that date,
 * and the head written off before getting a caravan (dead or lost), which stay received.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entry_order_dtes', function (Blueprint $table) {
            // The day the head were confirmed by count. Null until a reception by head.
            $table->date('heads_received_at')->nullable()->after('uncaravaned_head_count');
            // Received without caravan and written off before getting one: still received.
            $table->unsignedInteger('written_off_head_count')->default(0)->after('heads_received_at');
        });

        Schema::create('entry_order_pending_heads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('entry_order_id')->constrained('entry_orders')->onDelete('cascade');
            $table->foreignId('entry_order_dte_id')->constrained('entry_order_dtes')->onDelete('cascade');

            // Own destination batch or the order's transit batch.
            $table->foreignId('batch_id')->constrained('batches')->restrictOnDelete();

            $table->unsignedInteger('head_count');
            $table->timestamps();

            $table->unique(['entry_order_dte_id', 'batch_id'], 'eoph_dte_batch_unique');
            // Serves Batch::scopeWithPendingIdentification().
            $table->index(['batch_id', 'head_count'], 'eoph_batch_idx');
            $table->index(['company_id', 'entry_order_id'], 'eoph_company_order_idx');
        });

        // Head already received without caravan went to the order's batch: the only one there was.
        $now = now();
        DB::table('entry_order_dtes')
            ->join('entry_orders', 'entry_orders.id', '=', 'entry_order_dtes.entry_order_id')
            ->where('entry_order_dtes.uncaravaned_head_count', '>', 0)
            ->whereNotNull('entry_orders.batch_id')
            ->orderBy('entry_order_dtes.id')
            ->get([
                'entry_order_dtes.id as dte_id', 'entry_order_dtes.entry_order_id', 'entry_order_dtes.company_id',
                'entry_order_dtes.uncaravaned_head_count', 'entry_orders.batch_id',
            ])
            ->each(fn ($row) => DB::table('entry_order_pending_heads')->insert([
                'company_id' => $row->company_id,
                'entry_order_id' => $row->entry_order_id,
                'entry_order_dte_id' => $row->dte_id,
                'batch_id' => $row->batch_id,
                'head_count' => $row->uncaravaned_head_count,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_order_pending_heads');

        Schema::table('entry_order_dtes', function (Blueprint $table) {
            $table->dropColumn(['heads_received_at', 'written_off_head_count']);
        });
    }
};
