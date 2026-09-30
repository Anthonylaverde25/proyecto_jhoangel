<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The transfer order (CACT-01): what the transfer screen decides, persisted.
     *
     * Four tables because an order is a header, the destinations it names, the roll of animals
     * it commits and the history of what happened to it. The roll is what makes a partial
     * execution computable instead of deduced: every animal is PENDING, MOVED or SKIPPED.
     *
     * Nothing here is specific to CACT-01 (no work_template_id, no activity-change columns),
     * so hooking DEST-01 later is wiring its use case, not remodelling.
     */
    public function up(): void
    {
        Schema::create('transfer_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');

            // RESTRICT and not cascade: deleting a batch must not erase the record that moving
            // animals out of it was ordered.
            $table->foreignId('source_batch_id')->constrained('batches')->onDelete('restrict');
            $table->foreignId('destination_activity_id')->constrained('activities')->onDelete('restrict');

            // Issued by the server: it is what gets printed and what the scan resolves.
            $table->string('code', 32);

            // ISSUED | PARTIAL | EXECUTED | CLOSED_INCOMPLETE | CANCELLED
            $table->string('status', 24);

            // single | per_animal
            $table->string('destination_mode', 16);

            // Head ordered, frozen at issue time: execution is measured against this, and
            // recounting the roll later would give a number that moves with it.
            $table->unsignedInteger('planned_head_count');

            $table->date('movement_date');

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('emitted_at')->nullable();

            // An order can live its whole life without paper. This is not a state but a fact
            // that may or may not have happened, and it is what tells "fulfilled at the chute"
            // apart from "fulfilled from the desk".
            $table->timestamp('printed_at')->nullable();

            $table->timestamp('first_executed_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->string('responsable')->nullable();
            $table->text('observations')->nullable();
            $table->text('closing_reason')->nullable();

            $table->timestamps();

            // Sequential per company and day, so the uniqueness is per company too.
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'source_batch_id']);
        });

        Schema::create('transfer_order_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('transfer_order_id')->constrained('transfer_orders')->onDelete('cascade');

            // The normalised name the rows of the paper join this destination by, the same
            // rule as `destination_key` in the CACT-01 payload.
            $table->string('destination_key', 255);
            $table->string('label', 255);

            // An existing batch OR one to create, never both. Enforced in the domain and not
            // with a CHECK: the error has to be able to name which destination fails.
            $table->foreignId('target_batch_id')->nullable()->constrained('batches')->onDelete('restrict');
            $table->string('new_batch_name', 255)->nullable();
            $table->foreignId('new_batch_type_id')->nullable()->constrained('batch_types')->onDelete('restrict');

            // Three states, as in `batches`: the management system may be left undeclared and
            // asked at scan time. It is the same fact the M cell of the paper carries.
            $table->boolean('is_confined')->nullable();

            // The batch that ended up receiving the animals. It lets a second round go to the
            // batch the first one created instead of creating another with the same name.
            $table->foreignId('resolved_batch_id')->nullable()->constrained('batches')->onDelete('set null');

            $table->timestamps();

            $table->unique(['transfer_order_id', 'destination_key'], 'transfer_order_destinations_order_key_unique');
        });

        Schema::create('transfer_order_animals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('transfer_order_id')->constrained('transfer_orders')->onDelete('cascade');
            $table->foreignId('caravan_id')->constrained('caravans')->onDelete('cascade');

            // Null is an answer, not a missing value: the batch of this animal is decided at
            // the chute and the cell is printed blank on purpose.
            $table->foreignId('transfer_order_destination_id')->nullable()
                ->constrained('transfer_order_destinations')->onDelete('set null');

            // PENDING | MOVED | SKIPPED
            $table->string('status', 16)->default('PENDING');
            $table->timestamp('moved_at')->nullable();

            // The link goes from here to the movement and not the other way round:
            // `caravan_movements` is a hot table and does not need altering to know which
            // movement fulfilled which line.
            $table->foreignId('caravan_movement_id')->nullable()
                ->constrained('caravan_movements')->onDelete('set null');

            $table->timestamps();

            $table->unique(['transfer_order_id', 'caravan_id']);
            $table->index(['company_id', 'status']);
        });

        // A deliberate copy of service_order_histories.
        Schema::create('transfer_order_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('transfer_order_id')->constrained('transfer_orders')->onDelete('cascade');

            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('action_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('action_reason')->nullable();

            // How many head THIS execution moved, what brought it, how many were left. It is
            // what makes "PARTIAL" readable six months later.
            $table->json('action_metadata')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'transfer_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_order_histories');
        Schema::dropIfExists('transfer_order_animals');
        Schema::dropIfExists('transfer_order_destinations');
        Schema::dropIfExists('transfer_orders');
    }
};
