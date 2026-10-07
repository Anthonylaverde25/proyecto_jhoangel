<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A purchase may bring several categories, each with its own head ("30 Novillito + 20 Vaquillona").
 * The order's single category becomes its lines, and the order's head is their sum. Each received
 * animal points to the line it entered with, as it does with its breed line.
 *
 * down() keeps only the first line of each order: the others cannot fit in a single column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_order_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('entry_order_id')->constrained('entry_orders')->onDelete('cascade');
            $table->foreignId('category_id')->constrained('animal_categories')->onDelete('restrict');

            // Head bought of this category. Null only on a draft that has not declared it yet.
            $table->unsignedInteger('head_count')->nullable();

            // 1-based; printed as the number 1, 2, 3... (breeds take the letters).
            $table->unsignedTinyInteger('position');

            $table->timestamps();

            $table->unique(['entry_order_id', 'position']);
            $table->unique(['entry_order_id', 'category_id']);
        });

        Schema::table('entry_order_animals', function (Blueprint $table) {
            // Category line the animal entered with: declared on its row, or the only one its sex allows.
            $table->foreignId('entry_order_category_id')->nullable()->after('entry_order_breed_id')
                ->constrained('entry_order_categories')->onDelete('set null');
        });

        $now = now();

        foreach (DB::table('entry_orders')->whereNotNull('category_id')->get(['id', 'company_id', 'category_id', 'head_count']) as $order) {
            $lineId = DB::table('entry_order_categories')->insertGetId([
                'company_id' => $order->company_id,
                'entry_order_id' => $order->id,
                'category_id' => $order->category_id,
                'head_count' => $order->head_count,
                'position' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('entry_order_animals')->where('entry_order_id', $order->id)->update(['entry_order_category_id' => $lineId]);
        }

        Schema::table('entry_orders', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });

        Schema::table('entry_orders', function (Blueprint $table) {
            $table->dropColumn('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('entry_orders', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('head_count')->constrained('animal_categories')->onDelete('restrict');
        });

        foreach (DB::table('entry_order_categories')->where('position', 1)->get(['entry_order_id', 'category_id']) as $line) {
            DB::table('entry_orders')->where('id', $line->entry_order_id)->update(['category_id' => $line->category_id]);
        }

        Schema::table('entry_order_animals', function (Blueprint $table) {
            $table->dropForeign(['entry_order_category_id']);
        });

        Schema::table('entry_order_animals', function (Blueprint $table) {
            $table->dropColumn('entry_order_category_id');
        });

        Schema::dropIfExists('entry_order_categories');
    }
};
