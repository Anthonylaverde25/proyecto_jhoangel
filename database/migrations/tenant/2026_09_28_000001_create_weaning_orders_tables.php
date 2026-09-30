<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The weaning order (DEST-01): which calves are weaned, into which weaning batches, and
     * whether their category changes — committed before the chute, or registered after it.
     *
     * Its own tables and not a kind of transfer_orders: a weaning order may take calves from
     * several breeding batches, so the source batch lives on each line of the roll instead of
     * on the header, and every rule of transfer_orders built around one source batch would
     * have to be switched off for it. The lifecycle, the kind and the category mode are the
     * same, and reuse the same codes.
     */
    public function up(): void
    {
        Schema::create('weaning_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');

            // DS-YYYYMMDD-NNNN, issued by the server: what gets printed and what the scan resolves.
            $table->string('code', 32);

            // DRAFT | ISSUED | PARTIAL | EXECUTED | CLOSED_INCOMPLETE | CANCELLED
            $table->string('status', 24);

            // PLANNED (issued before the chute) | REGISTERED (loaded after the weaning happened)
            $table->string('kind', 16)->default('PLANNED');

            // single | per_animal
            $table->string('destination_mode', 16);

            // KEEP | DECLARED | AT_CHUTE
            $table->string('category_mode', 16)->default('KEEP');

            // The stage the weaned calves go to: the activity of the WEANING batch type. Declared
            // on the order even though nobody chooses it, so every weaning batch it names is
            // checked against it.
            $table->foreignId('destination_activity_id')->constrained('activities')->onDelete('restrict');

            // TRADITIONAL | ANTICIPATED | EARLY. Null: not declared, may be marked at the chute.
            $table->string('weaning_type', 16)->nullable();

            // Head ordered, frozen at issue time.
            $table->unsignedInteger('planned_head_count');

            // The date the weaning is planned for. The day it actually happened is declared by
            // whoever executes it and lives on each movement.
            $table->date('weaning_date');

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->onDelete('set null');
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
        });

        Schema::create('weaning_order_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('weaning_order_id')->constrained('weaning_orders')->onDelete('cascade');

            // The normalised name a handwritten row of the paper is joined by.
            $table->string('destination_key', 255);
            $table->string('label', 255);

            // An existing weaning batch OR the name of one to create, never both. There is no
            // batch type column: a weaning destination is always a WEANING batch.
            $table->foreignId('target_batch_id')->nullable()->constrained('batches')->onDelete('restrict');
            $table->string('new_batch_name', 255)->nullable();

            // Three states: the management system of a new batch may be left for the scan.
            $table->boolean('is_confined')->nullable();

            // The batch that ended up receiving the calves, so a second round reuses it.
            $table->foreignId('resolved_batch_id')->nullable()->constrained('batches')->onDelete('set null');

            $table->timestamps();

            $table->unique(['weaning_order_id', 'destination_key'], 'weaning_order_destinations_order_key_unique');
        });

        Schema::create('weaning_order_animals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('weaning_order_id')->constrained('weaning_orders')->onDelete('cascade');
            $table->foreignId('caravan_id')->constrained('caravans')->onDelete('cascade');

            // Where the calf was when the order took it: the breeding batch it leaves. Per line,
            // because one order may wean the calves of several breeding batches. Null for a calf
            // that was in no batch.
            $table->foreignId('source_batch_id')->nullable()->constrained('batches')->onDelete('restrict');

            // Null is an answer: the weaning batch of this calf is decided at the chute.
            $table->foreignId('weaning_order_destination_id')->nullable()
                ->constrained('weaning_order_destinations')->onDelete('set null');

            // Only in a DECLARED order. Null: the calf keeps its category.
            $table->foreignId('target_category_id')->nullable()->constrained('animal_categories')->onDelete('restrict');
            $table->foreignId('target_subcategory_id')->nullable()->constrained('animal_subcategories')->onDelete('restrict');

            // PENDING | WEANED | SKIPPED
            $table->string('status', 16)->default('PENDING');
            $table->timestamp('weaned_at')->nullable();

            // The WEANING movement that fulfilled the line. The weight lives in caravan_weights,
            // never here: an order commits calves, it does not measure them.
            $table->foreignId('caravan_movement_id')->nullable()
                ->constrained('caravan_movements')->onDelete('set null');

            $table->timestamps();

            $table->unique(['weaning_order_id', 'caravan_id']);
            $table->index(['company_id', 'status']);
        });

        // A deliberate copy of transfer_order_histories.
        Schema::create('weaning_order_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('weaning_order_id')->constrained('weaning_orders')->onDelete('cascade');

            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('action_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('action_reason')->nullable();

            // How many calves THIS execution weaned, from where, on which day, how many are left.
            $table->json('action_metadata')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'weaning_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weaning_order_histories');
        Schema::dropIfExists('weaning_order_animals');
        Schema::dropIfExists('weaning_order_destinations');
        Schema::dropIfExists('weaning_orders');
    }
};
