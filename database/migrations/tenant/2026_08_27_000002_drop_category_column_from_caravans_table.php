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
        Schema::table('caravans', function (Blueprint $table) {
            try {
                $table->dropIndex('caravans_category_teeth_index');
            } catch (\Throwable) {
                // Index might already be dropped or not present
            }

            if (Schema::hasColumn('caravans', 'category')) {
                $table->dropColumn('category');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('caravans', function (Blueprint $table) {
            if (!Schema::hasColumn('caravans', 'category')) {
                $table->string('category')->nullable()->after('identification');
                $table->index(['category', 'teeth'], 'caravans_category_teeth_index');
            }
        });
    }
};
