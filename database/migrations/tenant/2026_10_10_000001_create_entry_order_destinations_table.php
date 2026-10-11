<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the animals of an entry order go, by category line and sex: "calves, males → batch A;
 * calves, females → batch B". A received caravan goes to the rule of its line and sex, else to the
 * rule of its whole line, else to the order's transit batch, which is only created when some
 * line and sex the troop can bring has no rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_order_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('entry_order_id')->constrained('entry_orders')->onDelete('cascade');
            $table->foreignId('entry_order_category_id')->constrained('entry_order_categories')->onDelete('cascade');

            // ALL: the whole line. M / H: only that sex, for a line that admits both in a mixed troop.
            // Not nullable on purpose: a unique index does not compare NULLs.
            $table->string('sex_scope', 3)->default('ALL');

            // Own, active batch when declared and at every reception.
            $table->foreignId('destination_batch_id')->constrained('batches')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['entry_order_category_id', 'sex_scope'], 'eod_line_sex_unique');
            $table->index(['company_id', 'destination_batch_id'], 'eod_company_destination_idx');
            $table->index('entry_order_id', 'eod_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_order_destinations');
    }
};
