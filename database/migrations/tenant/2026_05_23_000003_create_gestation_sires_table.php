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
        Schema::create('gestation_sires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gestation_id')->constrained('caravan_gestations')->cascadeOnDelete();
            $table->foreignId('sire_id')->constrained('caravans')->cascadeOnDelete();
            $table->boolean('is_confirmed')->default(false);
            $table->timestamps();

            $table->unique(['gestation_id', 'sire_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gestation_sires');
    }
};
