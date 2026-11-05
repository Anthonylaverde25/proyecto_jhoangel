<?php

declare(strict_types=1);

use Database\Seeders\WeighingTypeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each cage declares the category that went on it, so a cow's average never reaches a calf.
     * Null: the animals with no category on record, or a load registered before this column.
     * The seeder refreshes the description of the cage re-weighing in the catalog.
     */
    public function up(): void
    {
        Schema::table('weighing_order_groups', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('weighing_order_id')
                ->constrained('animal_categories')->restrictOnDelete();
        });

        (new WeighingTypeSeeder())->run();
    }

    public function down(): void
    {
        Schema::table('weighing_order_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
