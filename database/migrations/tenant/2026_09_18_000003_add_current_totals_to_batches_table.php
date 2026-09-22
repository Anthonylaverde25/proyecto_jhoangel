<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reactive snapshot of the current membership of the batch, kept in sync through
     * the single entry point for changing the batch of an animal.
     *
     * `current_weight` is left untouched: it is already nullable, which is exactly what
     * an empty batch needs in order to say "no average" instead of showing a zero.
     */
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            if (!Schema::hasColumn('batches', 'total_weight')) {
                $table->decimal('total_weight', 12, 2)->nullable()->after('current_weight');
            }

            if (!Schema::hasColumn('batches', 'caravans_count')) {
                $table->unsignedInteger('caravans_count')->nullable()->after('total_weight');
            }

            if (!Schema::hasColumn('batches', 'weighed_count')) {
                $table->unsignedInteger('weighed_count')->nullable()->after('caravans_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn(['total_weight', 'caravans_count', 'weighed_count']);
        });
    }
};
