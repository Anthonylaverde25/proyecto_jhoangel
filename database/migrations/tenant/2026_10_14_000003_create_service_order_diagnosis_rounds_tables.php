<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The diagnosis of an order can take several days at the chute: each day is a round.
 *
 * A round only carries its date for now (method and responsible arrive with the veterinary
 * module). Every verdict given to a female is kept in the round it was given, so a re-check never
 * erases the previous result. Facts that must not block the work but need settling are kept as
 * incidents of the order, like the ones of the entry orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_order_diagnosis_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('service_order_id')->constrained('service_orders')->cascadeOnDelete();
            $table->date('round_date');
            $table->timestamps();

            $table->unique(['service_order_id', 'round_date'], 'uq_sodr_order_date');
        });

        Schema::create('service_order_female_diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('service_order_id')->constrained('service_orders')->cascadeOnDelete();
            $table->foreignId('diagnosis_round_id')
                ->constrained('service_order_diagnosis_rounds', 'id', 'fk_sofd_round')
                ->cascadeOnDelete();
            $table->foreignId('service_order_female_id')
                ->constrained('service_order_females', 'id', 'fk_sofd_female')
                ->cascadeOnDelete();
            $table->foreignId('female_caravan_id')->constrained('caravans', 'id', 'fk_sofd_caravan');

            $table->string('verdict', 20)->comment('PREGNANT, EMPTY, NOT_DIAGNOSED');
            $table->string('gestation_stage', 10)->nullable();
            $table->decimal('gestation_months', 3, 1)->nullable();
            $table->foreignId('gestation_id')
                ->nullable()
                ->constrained('caravan_gestations', 'id', 'fk_sofd_gestation')
                ->nullOnDelete();
            $table->string('not_diagnosed_reason', 30)->nullable();
            $table->string('not_diagnosed_notes', 255)->nullable();

            $table->foreignId('diagnosed_by_user_id')
                ->nullable()
                ->constrained('users', 'id', 'fk_sofd_user')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['service_order_female_id', 'id'], 'idx_sofd_female_latest');
            $table->index(['diagnosis_round_id', 'verdict'], 'idx_sofd_round_verdict');
        });

        Schema::create('service_order_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('service_order_id')->constrained('service_orders')->cascadeOnDelete();
            $table->foreignId('female_caravan_id')
                ->nullable()
                ->constrained('caravans', 'id', 'fk_soi_caravan')
                ->nullOnDelete();

            $table->string('type', 32)->comment('PREGNANT_OUTSIDE_SERVICE_BATCH');
            $table->text('detail');
            $table->json('metadata')->nullable();

            $table->string('status', 10)->default('OPEN')->comment('OPEN, RESOLVED');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by_user_id')
                ->nullable()
                ->constrained('users', 'id', 'fk_soi_resolved_by')
                ->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('raised_by_user_id')
                ->nullable()
                ->constrained('users', 'id', 'fk_soi_raised_by')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'idx_soi_company_status');
            $table->index('service_order_id', 'idx_soi_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_incidents');
        Schema::dropIfExists('service_order_female_diagnoses');
        Schema::dropIfExists('service_order_diagnosis_rounds');
    }
};
