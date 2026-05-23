<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('caravan_gestations', function (Blueprint $table) {
            $table->decimal('gestation_months', 3, 1)->default(3.0)->after('gestation_stage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('caravan_gestations', function (Blueprint $table) {
            $table->dropColumn('gestation_months');
        });
    }
};
