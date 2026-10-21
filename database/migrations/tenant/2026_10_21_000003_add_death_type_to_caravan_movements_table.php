<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BEFORE = ['ORIGIN', 'ENTRY', 'EXIT', 'TRANSFER', 'WEANING', 'PURCHASE'];

    private const AFTER = [...self::BEFORE, 'DEATH'];

    public function up(): void
    {
        // A death moves the animal to a death batch; the service exposure already reads DEATH.
        Schema::table('caravan_movements', function (Blueprint $table) {
            $table->enum('type', self::AFTER)->default('ENTRY')->change();
        });
    }

    public function down(): void
    {
        if (DB::table('caravan_movements')->where('type', 'DEATH')->exists()) {
            throw new RuntimeException('Cannot roll back: there are DEATH movements. Remove the deaths first.');
        }

        Schema::table('caravan_movements', function (Blueprint $table) {
            $table->enum('type', self::BEFORE)->default('ENTRY')->change();
        });
    }
};
