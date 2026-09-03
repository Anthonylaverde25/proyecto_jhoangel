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
        Schema::create('animal_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->enum('sex', ['M', 'H', 'BOTH'])->default('BOTH');
            $table->unsignedSmallInteger('min_age_months')->nullable();
            $table->unsignedSmallInteger('max_age_months')->nullable();
            $table->decimal('min_weight_kg', 8, 2)->nullable();
            $table->decimal('max_weight_kg', 8, 2)->nullable();
            $table->boolean('is_reproductive')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('animal_subcategories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('animal_categories')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->decimal('target_weight_min', 8, 2)->nullable();
            $table->decimal('target_weight_max', 8, 2)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('animal_subcategories');
        Schema::dropIfExists('animal_categories');
    }
};
