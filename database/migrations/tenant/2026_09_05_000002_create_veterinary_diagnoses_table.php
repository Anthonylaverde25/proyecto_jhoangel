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
        Schema::create('veterinary_diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('caravan_id')->constrained('caravans')->onDelete('restrict');
            $table->foreignId('pathogen_id')->constrained('pathogens')->onDelete('restrict');
            $table->foreignId('veterinarian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('diagnosis_date');
            $table->enum('status', ['CONFIRMED_POSITIVE', 'IN_TREATMENT', 'RESOLVED', 'SUSPECTED']);
            $table->date('resolution_date')->nullable();
            $table->text('treatment_notes')->nullable();
            $table->enum('source_context', ['PRE_SERVICE', 'TACTO_RECTAL', 'ROUTINE', 'EMERGENCY'])->default('PRE_SERVICE');
            $table->timestamps();

            $table->index(['company_id', 'caravan_id', 'status']);
            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('veterinary_diagnoses');
    }
};
