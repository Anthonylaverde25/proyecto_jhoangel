<?php

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
        Schema::create('caravan_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caravan_id')->constrained()->onDelete('cascade');
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->string('renspa');
            $table->enum('type', ['ORIGIN', 'ENTRY', 'EXIT', 'TRANSFER'])->default('ENTRY');
            $table->dateTime('movement_date');
            $table->text('observations')->nullable();
            $table->timestamps();

            // Index for performance
            $table->index(['company_id', 'caravan_id', 'movement_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('caravan_movements');
    }
};
