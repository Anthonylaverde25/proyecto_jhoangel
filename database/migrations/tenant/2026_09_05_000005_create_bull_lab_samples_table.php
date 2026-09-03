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
        Schema::create('bull_lab_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('caravan_id')->constrained('caravans')->onDelete('restrict');
            $table->foreignId('evaluation_id')->nullable()->constrained('bull_health_evaluations')->nullOnDelete();
            $table->enum('sample_type', ['PREPUCE_SCRAPE', 'BLOOD_SEROLOGY']);
            $table->unsignedTinyInteger('sample_round')->default(1);
            $table->date('sample_date');
            $table->string('tube_number', 50)->nullable();
            $table->enum('status', ['PENDING_RESULTS', 'NEGATIVE_CLEARED', 'POSITIVE_DETECTED'])->default('PENDING_RESULTS');
            $table->string('protocol_number', 100)->nullable();
            $table->date('result_date')->nullable();
            $table->foreignId('pathogen_id')->nullable()->constrained('pathogens')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'caravan_id', 'status'], 'bls_comp_caravan_status_idx');
            $table->index(['company_id', 'sample_type', 'status'], 'bls_comp_type_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bull_lab_samples');
    }
};
