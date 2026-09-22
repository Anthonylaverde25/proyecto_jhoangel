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
        Schema::table('company_activity', function (Blueprint $table) {
            $table->boolean('is_initial')->default(false)->after('is_enabled');
            $table->boolean('is_final')->default(false)->after('is_initial');
            $table->unsignedSmallInteger('sort_order')->default(1)->after('is_final');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_activity', function (Blueprint $table) {
            $table->dropColumn(['is_initial', 'is_final', 'sort_order']);
        });
    }
};
