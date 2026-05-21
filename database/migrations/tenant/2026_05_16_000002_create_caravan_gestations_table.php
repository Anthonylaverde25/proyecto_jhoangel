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
        Schema::create('caravan_gestations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caravan_id')->constrained('caravans')->cascadeOnDelete();
            
            $table->date('start_date')->nullable();
            $table->date('estimated_due_date')->nullable();
            
            $table->boolean('is_current')->default(true);
            
            // The result will be stored as string matching GestationResult enum, or null if ongoing.
            $table->string('result')->nullable(); 
            
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // Index for querying active gestations quickly
            $table->index(['caravan_id', 'is_current']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('caravan_gestations');
    }
};
