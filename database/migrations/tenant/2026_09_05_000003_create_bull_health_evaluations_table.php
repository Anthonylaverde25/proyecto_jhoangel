<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bull_health_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('caravan_id')->constrained('caravans')->onDelete('restrict');
            $table->date('last_evaluation_date')->nullable();
            $table->text('aplomo_notes')->nullable();
            $table->decimal('scrotal_circumference_cm', 4, 1)->nullable();
            $table->decimal('body_condition_score', 3, 1)->nullable();
            $table->string('libido', 30)->default('MEDIA');
            $table->enum('status', ['APT', 'UNFIT', 'IN_TREATMENT', 'PENDING_EVALUATION'])->default('PENDING_EVALUATION');
            $table->text('observations')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'caravan_id']);
            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bull_health_evaluations');
    }
};
