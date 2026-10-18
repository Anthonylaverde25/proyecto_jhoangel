<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** U of model 2.1: units waiting for their invoice. The average is over quantity − unvalued_quantity. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_stocks', function (Blueprint $table) {
            $table->decimal('unvalued_quantity', 18, 4)->default(0)->after('quantity');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE item_stocks ADD CONSTRAINT chk_istock_unvalued CHECK (unvalued_quantity >= 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE item_stocks DROP CHECK chk_istock_unvalued');
        }

        Schema::table('item_stocks', function (Blueprint $table) {
            $table->dropColumn('unvalued_quantity');
        });
    }
};
