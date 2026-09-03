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
            if (Schema::hasColumn('caravans', 'breed')) {
                $table->dropColumn('breed');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('caravans', function (Blueprint $table) {
            if (!Schema::hasColumn('caravans', 'breed')) {
                $table->string('breed')->nullable()->after('category_id');
            }
        });
    }
};
