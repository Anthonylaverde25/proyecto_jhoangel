<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The exit order: animals leaving the company, the reason they leave for (SALE in this
     * delivery), the optional DTEs that cover the trip, and its history. Lines keep a snapshot
     * of where each animal was when it left, so the breakdown by activity never moves.
     */
    public function up(): void
    {
        Schema::create('exit_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // EG-YYYYMMDD-NNNN, sequential per company and day. "EG" has neither O nor I.
            $table->string('code', 32);

            // PLANNED | REGISTERED. Declared, never inferred from dates.
            $table->string('kind', 16);

            // SALE. Other reasons (auction, own consumption, loss) come later.
            $table->string('reason', 16)->default('SALE');

            // ISSUED | PARTIAL | EXECUTED | CLOSED_INCOMPLETE | CANCELLED
            $table->string('status', 24);

            // The partner that buys (role CUSTOMER_LIVESTOCK). Named by its role in this document,
            // like entry_orders.provider_id. Required for SALE (CHECK below); nullable for
            // reasons without a counterparty.
            $table->unsignedBigInteger('buyer_partner_id')->nullable();

            // Planned date for PLANNED, actual date for REGISTERED.
            $table->date('exit_date');

            // Frozen at issue: execution is measured against it.
            $table->unsignedInteger('planned_head_count');

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('emitted_at')->nullable();
            $table->timestamp('first_executed_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->string('responsable')->nullable();
            $table->text('observations')->nullable();
            $table->text('closing_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->unique(['id', 'company_id'], 'uq_exo_id_company');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'exit_date']);
            $table->index(['company_id', 'buyer_partner_id']);

            $table->foreign(['buyer_partner_id', 'company_id'])
                ->references(['id', 'company_id'])->on('partners')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE exit_orders ADD CONSTRAINT chk_exo_sale_buyer
                CHECK (reason <> 'SALE' OR buyer_partner_id IS NOT NULL)");
        }

        Schema::create('exit_order_animals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('exit_order_id');
            $table->foreignId('caravan_id')->constrained('caravans')->restrictOnDelete();

            // PENDING | EXITED | SKIPPED
            $table->string('status', 16)->default('PENDING');

            // Snapshot at the moment it left; null while PENDING or SKIPPED.
            $table->unsignedBigInteger('from_batch_id')->nullable();
            $table->foreignId('activity_id')->nullable()->constrained('activities')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('animal_categories')->restrictOnDelete();
            $table->boolean('was_pregnant')->nullable();

            // All optional: weight at the chute, agreed price per kg, and the line amount
            // (computed from both, or typed directly for a per-head sale).
            $table->decimal('weight_kg', 10, 2)->nullable();
            $table->decimal('price_per_kg', 14, 2)->nullable();
            $table->decimal('amount', 16, 2)->nullable();

            $table->timestamp('exited_at')->nullable();
            $table->foreignId('caravan_movement_id')->nullable()->constrained('caravan_movements')->nullOnDelete();
            $table->foreignId('caravan_weight_id')->nullable()->constrained('caravan_weights')->nullOnDelete();
            $table->string('skipped_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['exit_order_id', 'caravan_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'activity_id']);

            $table->foreign(['exit_order_id', 'company_id'])
                ->references(['id', 'company_id'])->on('exit_orders')->cascadeOnDelete();
            $table->foreign(['from_batch_id', 'company_id'])
                ->references(['id', 'company_id'])->on('batches')->restrictOnDelete();
        });

        // E1: an animal leaves once. Partial unique index in SQLite, generated column in MySQL.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX uq_eoa_exited_caravan
                ON exit_order_animals (caravan_id) WHERE status = 'EXITED'");
        } else {
            DB::statement("ALTER TABLE exit_order_animals
                ADD COLUMN exited_caravan_id BIGINT UNSIGNED
                    AS (CASE WHEN status = 'EXITED' THEN caravan_id END) STORED,
                ADD UNIQUE INDEX uq_eoa_exited_caravan (exited_caravan_id)");
        }

        // A quantity document, as entry_order_dtes: number, date, head. Optional.
        Schema::create('exit_order_dtes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('exit_order_id');
            $table->string('dte_number', 40);
            $table->date('dte_date');
            $table->unsignedInteger('head_count');
            $table->foreignId('loaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observations')->nullable();
            $table->timestamps();

            // A DTE covers one trip: it cannot be loaded on two exits of the company.
            $table->unique(['company_id', 'dte_number']);
            $table->index(['company_id', 'exit_order_id']);

            $table->foreign(['exit_order_id', 'company_id'])
                ->references(['id', 'company_id'])->on('exit_orders')->cascadeOnDelete();
        });

        // A deliberate copy of transfer_order_histories.
        Schema::create('exit_order_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('exit_order_id');
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('action_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('action_reason')->nullable();
            // Head exited by this action, DTE loaded, what was left pending.
            $table->json('action_metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'exit_order_id']);

            $table->foreign(['exit_order_id', 'company_id'])
                ->references(['id', 'company_id'])->on('exit_orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exit_order_histories');
        Schema::dropIfExists('exit_order_dtes');
        // The generated column and the partial index go with the table.
        Schema::dropIfExists('exit_order_animals');

        if (DB::getDriverName() !== 'sqlite' && Schema::hasTable('exit_orders')) {
            DB::statement('ALTER TABLE exit_orders DROP CHECK chk_exo_sale_buyer');
        }
        Schema::dropIfExists('exit_orders');
    }
};
