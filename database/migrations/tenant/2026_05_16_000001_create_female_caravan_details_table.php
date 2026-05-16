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
        Schema::create('female_caravan_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caravan_id')->constrained('caravans')->cascadeOnDelete();
            $table->boolean('is_empty')->default(true);
            $table->string('arrival_category', 50);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('female_caravan_details');
    }
};
