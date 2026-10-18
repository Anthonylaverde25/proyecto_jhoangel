<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * direction 0 is a valuation: value enters, the balance does not move. quantity is the units it
 * values (0 for a non-recoverable tax, Q3). unvalued_* rebuild U along the kardex.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('unvalued_quantity', 18, 4)->default(0)->after('quantity');
            $table->decimal('unvalued_balance_after', 18, 4)->default(0)->after('balance_after');
            $table->decimal('unit_cost', 18, 6)->nullable()->change();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE stock_movements DROP CHECK chk_smov_direction');
            DB::statement('ALTER TABLE stock_movements DROP CHECK chk_smov_quantity');

            $checks = [
                'chk_smov_direction' => 'direction IN (-1, 0, 1)',
                'chk_smov_quantity' => '(direction = 0 AND quantity >= 0) OR (direction <> 0 AND quantity > 0)',
                'chk_smov_unvalued' => 'unvalued_quantity >= 0 AND unvalued_quantity <= quantity
                    AND unvalued_balance_after >= 0 AND (direction <> 0 OR unvalued_quantity = 0)',
                'chk_smov_unit_cost' => 'unit_cost IS NOT NULL OR unvalued_quantity = quantity',
            ];
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            foreach (['chk_smov_direction', 'chk_smov_quantity', 'chk_smov_unvalued', 'chk_smov_unit_cost'] as $name) {
                DB::statement("ALTER TABLE stock_movements DROP CHECK {$name}");
            }
            DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT chk_smov_direction CHECK (direction IN (-1, 1))');
            DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT chk_smov_quantity CHECK (quantity > 0)');
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn(['unvalued_quantity', 'unvalued_balance_after']);
            $table->decimal('unit_cost', 18, 6)->nullable(false)->change();
        });
    }
};
