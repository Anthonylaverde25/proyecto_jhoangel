<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The part of a line that has no value yet (model 2.4): units in from an unvalued delivery note, or
 * units out of one. total_cost keeps the known cost; unit_cost is NULL when nothing is valued.
 * unabsorbed_cost is the share of a valuation no valued stock could take (Q3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_document_lines', function (Blueprint $table) {
            $table->decimal('unvalued_quantity', 18, 4)->default(0)->after('quantity');
            $table->decimal('unabsorbed_cost', 18, 4)->nullable()->after('total_cost');  // VALUATION only
            $table->decimal('unit_cost', 18, 6)->nullable()->change();
        });

        if (DB::getDriverName() !== 'sqlite') {
            $checks = [
                'chk_idl_unvalued' => 'unvalued_quantity >= 0 AND unvalued_quantity <= quantity',
                'chk_idl_unit_cost' => 'unit_cost IS NOT NULL OR (quantity > 0 AND unvalued_quantity = quantity)',
                'chk_idl_unabsorbed' => 'unabsorbed_cost IS NULL OR unabsorbed_cost > 0',
            ];
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE item_document_lines ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            foreach (['chk_idl_unvalued', 'chk_idl_unit_cost', 'chk_idl_unabsorbed'] as $name) {
                DB::statement("ALTER TABLE item_document_lines DROP CHECK {$name}");
            }
        }

        Schema::table('item_document_lines', function (Blueprint $table) {
            $table->dropColumn(['unvalued_quantity', 'unabsorbed_cost']);
            $table->decimal('unit_cost', 18, 6)->nullable(false)->change();
        });
    }
};
