<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which providers supply an item. Both ends are of the same company (composite keys). The last
 * purchase line (price and date) is added in B2 together with item_document_lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_provider', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('provider_id');
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->boolean('is_preferred')->default(false);
            $table->timestamps();

            $table->unique(['item_id', 'provider_id'], 'uq_iprov_item_provider');
            $table->index(['company_id', 'provider_id'], 'idx_iprov_company_provider');

            $table->foreign(['item_id', 'company_id'])             // item_provider_item_id_company_id_foreign
                ->references(['id', 'company_id'])->on('items')
                ->cascadeOnDelete();
            $table->foreign(['provider_id', 'company_id'])         // item_provider_provider_id_company_id_foreign
                ->references(['id', 'company_id'])->on('providers')
                ->cascadeOnDelete();
        });

        $this->createOnePreferredGuard();
    }

    public function down(): void
    {
        Schema::dropIfExists('item_provider');
    }

    /**
     * At most one preferred provider per item. MySQL: functional unique index. SQLite: partial
     * unique index.
     */
    private function createOnePreferredGuard(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX uq_iprov_one_preferred
                ON item_provider (item_id) WHERE is_preferred = 1');

            return;
        }

        DB::statement('CREATE UNIQUE INDEX uq_iprov_one_preferred
            ON item_provider ((IF(is_preferred, item_id, NULL)))');
    }
};
