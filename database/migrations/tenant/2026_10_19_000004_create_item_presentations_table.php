<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commercial packaging of an item (D16), e.g. "Frasco x 500 ml": converts what the paper says
 * into the item's unit. Belongs to the item, so it carries no company_id of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_presentations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->string('name', 64);
            $table->decimal('factor_to_item_unit', 18, 4);        // item units in one presentation
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['item_id', 'name'], 'uq_ipres_item_name');
            // Lets a B2 document line guarantee the presentation belongs to the same item.
            $table->unique(['id', 'item_id'], 'uq_ipres_id_item');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE item_presentations ADD CONSTRAINT chk_ipres_factor_positive
                CHECK (factor_to_item_unit > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_presentations');
    }
};
