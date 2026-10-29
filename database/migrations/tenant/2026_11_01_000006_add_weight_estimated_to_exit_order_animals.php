<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A load sold by the truck's total kg spreads that weight over the animals: each line keeps
        // its share, marked as estimated, and no weighing of the animal is created from it.
        Schema::table('exit_order_animals', function (Blueprint $table) {
            $table->boolean('weight_estimated')->default(false)->after('weight_kg');
        });
    }

    public function down(): void
    {
        Schema::table('exit_order_animals', function (Blueprint $table) {
            $table->dropColumn('weight_estimated');
        });
    }
};
