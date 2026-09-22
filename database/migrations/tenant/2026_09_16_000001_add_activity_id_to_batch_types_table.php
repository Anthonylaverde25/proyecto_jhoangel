<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalogue-level constraint: a batch type is either specific to one activity
     * or cross-cutting (NULL) and therefore offered in every activity.
     */
    public function up(): void
    {
        if (Schema::hasColumn('batch_types', 'activity_id')) {
            return;
        }

        Schema::table('batch_types', function (Blueprint $table) {
            $table->foreignId('activity_id')
                ->nullable()
                ->constrained('activities')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('batch_types', 'activity_id')) {
            return;
        }

        Schema::table('batch_types', function (Blueprint $table) {
            // SQLite does not support DROP FOREIGN KEY: the driver throws.
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['activity_id']);
            }

            $table->dropColumn('activity_id');
        });
    }
};
