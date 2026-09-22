<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Management system of the batch instance: confined (feedlot pen) or extensive
     * (pasture). Orthogonal to the batch type, and mutable over the batch lifetime.
     */
    public function up(): void
    {
        if (Schema::hasColumn('batches', 'is_confined')) {
            return;
        }

        Schema::table('batches', function (Blueprint $table) {
            $table->boolean('is_confined')->default(false)->after('knows_to_eat');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('batches', 'is_confined')) {
            return;
        }

        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn('is_confined');
        });
    }
};
