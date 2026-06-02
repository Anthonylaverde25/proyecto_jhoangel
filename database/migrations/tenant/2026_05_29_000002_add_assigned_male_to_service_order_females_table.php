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
        Schema::table('service_order_females', function (Blueprint $table) {
            $table->foreignId('assigned_male_caravan_id')
                ->nullable()
                ->after('female_caravan_id')
                ->constrained('caravans')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_order_females', function (Blueprint $table) {
            $table->dropForeign(['assigned_male_caravan_id']);
            $table->dropColumn('assigned_male_caravan_id');
        });
    }
};
