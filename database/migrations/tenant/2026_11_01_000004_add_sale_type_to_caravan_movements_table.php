<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BEFORE = ['ORIGIN', 'ENTRY', 'EXIT', 'TRANSFER', 'WEANING', 'PURCHASE', 'DEATH'];
    private const AFTER = [...self::BEFORE, 'SALE'];

    public function up(): void
    {
        // A sale moves the animal to the exit batch; the service exposure already reads SALE.
        Schema::table('caravan_movements', function (Blueprint $table) {
            $table->enum('type', self::AFTER)->default('ENTRY')->change();
        });
    }

    public function down(): void
    {
        if (DB::table('caravan_movements')->where('type', 'SALE')->exists()) {
            throw new RuntimeException('Cannot roll back: there are SALE movements. Remove the exits first.');
        }

        Schema::table('caravan_movements', function (Blueprint $table) {
            $table->enum('type', self::BEFORE)->default('ENTRY')->change();
        });
    }
};
