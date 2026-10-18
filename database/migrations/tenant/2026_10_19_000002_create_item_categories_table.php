<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company-owned item categories (D10), organised as a tree through parent_id. The parent is
 * always of the same company (composite key); cycles are prevented by the use case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 32);                            // HEALTH, ANTIPARASITICS, ... (English)
            $table->string('name', 100);                           // Spanish display name
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('display_order')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'uq_icat_company_code');
            $table->unique(['id', 'company_id'], 'uq_icat_id_company');
            $table->index(['company_id', 'parent_id'], 'idx_icat_company_parent');
        });

        // Self-reference added once the (id, company_id) key exists.
        Schema::table('item_categories', function (Blueprint $table) {
            $table->foreign(['parent_id', 'company_id'])           // item_categories_parent_id_company_id_foreign
                ->references(['id', 'company_id'])->on('item_categories')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_categories');
    }
};
