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
        Schema::create('workday_caravan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workday_id')->constrained('workdays')->cascadeOnDelete();
            $table->foreignId('caravan_id')->constrained('caravans')->cascadeOnDelete();
            $table->timestamps();
            
            // Un animal solo puede estar registrado una vez por jornada
            $table->unique(['workday_id', 'caravan_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workday_caravan');
    }
};
