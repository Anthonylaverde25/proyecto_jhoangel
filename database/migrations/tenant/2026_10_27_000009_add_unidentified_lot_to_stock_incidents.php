<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The "Identificar partida" stock notice (SL6). SQLite relies on the enum, like every CHECK. */
return new class extends Migration
{
    private const BEFORE = "'NEGATIVE_BALANCE','CRITICAL_STOCK','COUNT_DISCREPANCY'";

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        DB::statement('ALTER TABLE stock_incidents DROP CHECK chk_sinc_type');
        DB::statement('ALTER TABLE stock_incidents ADD CONSTRAINT chk_sinc_type CHECK (type IN (' . self::BEFORE . ",'UNIDENTIFIED_SEMEN_LOT'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        DB::statement('ALTER TABLE stock_incidents DROP CHECK chk_sinc_type');
        DB::statement('ALTER TABLE stock_incidents ADD CONSTRAINT chk_sinc_type CHECK (type IN (' . self::BEFORE . '))');
    }
};
