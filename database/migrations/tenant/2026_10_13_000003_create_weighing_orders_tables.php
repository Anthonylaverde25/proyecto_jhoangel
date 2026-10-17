<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weighing_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');

            // PS-YYYYMMDD-NNNN, issued by the server: what gets printed and what the scan resolves.
            $table->string('code', 32);
            // DRAFT | ISSUED | PARTIAL | EXECUTED | CLOSED_INCOMPLETE | CANCELLED
            $table->string('status', 24);
            // PLANNED | REGISTERED
            $table->string('kind', 16)->default('PLANNED');
            // INDIVIDUAL (chute) | GROUP (cage or truck scale)
            $table->string('mode', 16);

            $table->foreignId('weighing_type_id')->constrained('weighing_types')->restrictOnDelete();
            // Required in GROUP mode: a cage weighing averages a single batch.
            $table->foreignId('batch_id')->nullable()->constrained('batches')->restrictOnDelete();

            // GROUP only. LOADS: each cage on weighing_order_groups. AVERAGE: the average per head
            // already worked out, declared with the heads it covers. Never a made-up load.
            $table->string('batch_entry', 12)->nullable();
            $table->unsignedInteger('declared_head_count')->nullable();
            $table->decimal('declared_average_weight', 8, 2)->nullable();

            $table->string('shrunk_condition', 16)->default('FULL_WEIGHT');
            $table->decimal('shrink_pct', 4, 2)->nullable();

            // Planned day. The real one is declared at execution and lives on each weight.
            $table->date('weighing_date');
            $table->time('weighing_time')->nullable();
            $table->unsignedInteger('planned_head_count');

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('emitted_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('first_executed_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->string('responsable')->nullable();
            $table->text('observations')->nullable();
            $table->text('closing_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'kind']);
            $table->index(['batch_id', 'weighing_date']);
        });

        Schema::create('weighing_order_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('weighing_order_id')->constrained('weighing_orders')->onDelete('cascade');

            // Cage number as written on the sheet.
            $table->unsignedSmallInteger('sequence');
            $table->unsignedInteger('head_count');
            $table->decimal('gross_weight', 12, 2);
            $table->decimal('tare_weight', 10, 2)->default(0);
            $table->timestamp('weighed_at')->nullable();
            $table->timestamps();

            // The average is (gross - tare) / head_count, derived, never stored.
            $table->unique(['weighing_order_id', 'sequence']);
        });

        Schema::create('weighing_order_animals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('weighing_order_id')->constrained('weighing_orders')->onDelete('cascade');
            $table->foreignId('caravan_id')->constrained('caravans')->onDelete('cascade');

            // Where the animal was when the order took it.
            $table->foreignId('source_batch_id')->nullable()->constrained('batches')->restrictOnDelete();
            // Only when the cage was identified: which load this animal went on.
            $table->foreignId('weighing_order_group_id')->nullable()
                ->constrained('weighing_order_groups')->nullOnDelete();

            // PENDING | WEIGHED | SKIPPED
            $table->string('status', 16)->default('PENDING');
            // Declared on the sheet: the animal was not in the issued list.
            $table->boolean('out_of_order')->default(false);

            // The weight that fulfilled the line. The kilos live in caravan_weights, never here.
            $table->foreignId('caravan_weight_id')->nullable()
                ->constrained('caravan_weights')->nullOnDelete();
            $table->timestamp('weighed_at')->nullable();
            $table->timestamps();

            $table->unique(['weighing_order_id', 'caravan_id']);
            $table->index(['company_id', 'status']);
        });

        // A deliberate copy of weaning_order_histories.
        Schema::create('weighing_order_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('weighing_order_id')->constrained('weighing_orders')->onDelete('cascade');
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('caravan_weights', function (Blueprint $table) {
            $table->foreignId('weighing_order_id')->nullable()->after('weighing_type_id')
                ->constrained('weighing_orders')->nullOnDelete();
        });

        Schema::table('batch_weights', function (Blueprint $table) {
            $table->foreignId('weighing_order_id')->nullable()->after('weighing_type_id')
                ->constrained('weighing_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('batch_weights', function (Blueprint $table) {
            $table->dropConstrainedForeignId('weighing_order_id');
        });

        Schema::table('caravan_weights', function (Blueprint $table) {
            $table->dropConstrainedForeignId('weighing_order_id');
        });

        Schema::dropIfExists('weighing_order_histories');
        Schema::dropIfExists('weighing_order_animals');
        Schema::dropIfExists('weighing_order_groups');
        Schema::dropIfExists('weighing_orders');
    }
};
