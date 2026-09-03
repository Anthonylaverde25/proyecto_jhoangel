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
        // 1. Create colors table
        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->nullable()->unique();
            $table->timestamps();
        });

        // 2. Create breed_color pivot table
        Schema::create('breed_color', function (Blueprint $table) {
            $table->id();
            $table->foreignId('breed_id')->constrained('breeds')->cascadeOnDelete();
            $table->foreignId('color_id')->constrained('colors')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['breed_id', 'color_id']);
        });

        // 3. Add color_id to caravans table
        Schema::table('caravans', function (Blueprint $table) {
            if (!Schema::hasColumn('caravans', 'color_id')) {
                $table->foreignId('color_id')->nullable()->after('breed_id')->constrained('colors')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('caravans', function (Blueprint $table) {
            if (Schema::hasColumn('caravans', 'color_id')) {
                $table->dropForeign(['color_id']);
                $table->dropColumn('color_id');
            }
        });

        Schema::dropIfExists('breed_color');
        Schema::dropIfExists('colors');
    }
};
