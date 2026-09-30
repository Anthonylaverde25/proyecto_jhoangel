<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the animals of an order change category, declared by whoever issues it.
     *
     * KEEP (the default, and what every existing order was) | DECLARED | AT_CHUTE.
     *
     * The target lives on each line of the roll and not on the order: an order of male and
     * female calves needs Novillito for some and Vaquillona for others. Null on a line means
     * that animal keeps its category. Only DECLARED fills it.
     */
    public function up(): void
    {
        Schema::table('transfer_orders', function (Blueprint $table) {
            $table->string('category_mode', 16)->default('KEEP')->after('destination_mode');
        });

        Schema::table('transfer_order_animals', function (Blueprint $table) {
            $table->foreignId('target_category_id')->nullable()->after('transfer_order_destination_id')
                ->constrained('animal_categories')->onDelete('restrict');
            // Invariant kept in the domain: when present, its category is target_category_id.
            $table->foreignId('target_subcategory_id')->nullable()->after('target_category_id')
                ->constrained('animal_subcategories')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_order_animals', function (Blueprint $table) {
            $table->dropForeign(['target_subcategory_id']);
            $table->dropForeign(['target_category_id']);
            $table->dropColumn(['target_category_id', 'target_subcategory_id']);
        });

        Schema::table('transfer_orders', function (Blueprint $table) {
            $table->dropColumn('category_mode');
        });
    }
};
