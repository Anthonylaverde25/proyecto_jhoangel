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
        Schema::table('batches', function (Blueprint $table) {
            $table->decimal('min_weight', 8, 2)->nullable()->after('current_weight');
            $table->decimal('max_weight', 8, 2)->nullable()->after('min_weight');
            $table->boolean('knows_to_eat')->default(false)->after('max_weight');
            $table->integer('age_in_months')->nullable()->after('knows_to_eat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn(['min_weight', 'max_weight', 'knows_to_eat', 'age_in_months']);
        });
    }
};
