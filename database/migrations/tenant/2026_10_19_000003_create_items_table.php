<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company-owned items (D9, D11): articles, optionally stock-tracked, and services, never
 * stocked. The unit is the stock unit of an article or the billing unit of a service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('item_category_id')->nullable();
            $table->unsignedBigInteger('unit_of_measure_id');
            $table->string('code', 32);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('type', 16);                            // ARTICLE | SERVICE
            $table->boolean('track_stock')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'uq_items_company_code');
            $table->unique(['id', 'company_id'], 'uq_items_id_company');
            $table->index(['company_id', 'type', 'is_active'], 'idx_items_company_type');
            $table->index(['company_id', 'name'], 'idx_items_company_name');

            $table->foreign(['item_category_id', 'company_id'])    // items_item_category_id_company_id_foreign
                ->references(['id', 'company_id'])->on('item_categories')
                ->restrictOnDelete();
            $table->foreign(['unit_of_measure_id', 'company_id'])  // items_unit_of_measure_id_company_id_foreign
                ->references(['id', 'company_id'])->on('units_of_measure')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE items ADD CONSTRAINT chk_items_type
                CHECK (type IN ('ARTICLE','SERVICE'))");
            DB::statement("ALTER TABLE items ADD CONSTRAINT chk_items_service_no_stock
                CHECK (type <> 'SERVICE' OR track_stock = 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
