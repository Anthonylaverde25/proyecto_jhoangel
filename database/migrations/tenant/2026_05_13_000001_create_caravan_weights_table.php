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
        Schema::create('caravan_weights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caravan_id')->constrained('caravans')->onDelete('cascade');
            $table->decimal('weight', 10, 2);
            $table->boolean('current')->default(false);
            $table->date('weighing_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Index for efficient lookup of current weight per caravan
            $table->index(['caravan_id', 'current']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('caravan_weights');
    }
};
