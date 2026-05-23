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
            $table->string('gestation_stage')->default('head')->after('is_current');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('caravan_gestations', function (Blueprint $table) {
            $table->dropColumn('gestation_stage');
        });
    }
};
