<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The birth order (PAR-01): which pregnant females are expected to calve, issued before the
     * calving rounds and fulfilled over several of them, or registered after the calvings happened.
     *
     * There is no destination: a calf is born in the batch its mother is in on the day it is born,
     * and that batch is read by the server, never declared. The roll is of MOTHERS and their
     * gestation — the calf does not exist yet — and each line ends in what happened: a live calf
     * (the calf it created), a stillbirth or an abortion.
     */
    public function up(): void
    {
        Schema::create('birth_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');

            // PA-YYYYMMDD-NNNN, issued by the server: what gets printed and what the scan resolves.
            $table->string('code', 32);

            // DRAFT | ISSUED | PARTIAL | EXECUTED | CLOSED_INCOMPLETE | CANCELLED
            $table->string('status', 24);

            // PLANNED (issued before the rounds) | REGISTERED (loaded after the calvings happened)
            $table->string('kind', 16)->default('PLANNED');

            // The calving window the order was planned for. Informative: each calving carries its
            // own date, declared per row.
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            // Females ordered, frozen at issue time. Calvings found outside the order are added to
            // the roll as unplanned lines and do not change it.
            $table->unsignedInteger('planned_head_count');

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

        Schema::create('birth_order_animals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('birth_order_id')->constrained('birth_orders')->onDelete('cascade');
            $table->foreignId('mother_caravan_id')->constrained('caravans')->onDelete('restrict');

            // The gestation this line closes. Null for an unplanned calving of a female the system
            // did not know was pregnant.
            $table->foreignId('gestation_id')->nullable()->constrained('caravan_gestations')->onDelete('set null');

            // Where the mother was when the order took her. Only a reference: the calf is born in
            // the batch the mother is in on the day it is born.
            $table->foreignId('source_batch_id')->nullable()->constrained('batches')->onDelete('set null');

            // A calving found at the round that the order did not list.
            $table->boolean('unplanned')->default(false);

            // PENDING | BORN | LOST | SKIPPED
            $table->string('status', 16)->default('PENDING');

            // LIVE | STILLBORN | ABORTION — declared, never inferred.
            $table->string('outcome', 16)->nullable();

            // The day of the calving or the loss.
            $table->date('event_date')->nullable();

            // The calf a LIVE calving created, and the batch it was born in (its mother's).
            $table->foreignId('calf_caravan_id')->nullable()->constrained('caravans')->onDelete('set null');
            $table->foreignId('calf_batch_id')->nullable()->constrained('batches')->onDelete('set null');

            $table->timestamp('executed_at')->nullable();
            $table->text('observations')->nullable();

            $table->timestamps();

            $table->unique(['birth_order_id', 'mother_caravan_id']);
            $table->index(['company_id', 'status']);
            $table->index(['mother_caravan_id', 'status']);
        });

        // A deliberate copy of weaning_order_histories.
        Schema::create('birth_order_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('birth_order_id')->constrained('birth_orders')->onDelete('cascade');

            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('action_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('action_reason')->nullable();

            // What THIS execution resolved: calvings, losses, unplanned lines, how many are left.
            $table->json('action_metadata')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'birth_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birth_order_histories');
        Schema::dropIfExists('birth_order_animals');
        Schema::dropIfExists('birth_orders');
    }
};
