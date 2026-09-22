<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A batch aggregate is a statistic over a set of animals. Without the size of that
     * set the number cannot be interpreted, and a change of the set cannot be told
     * apart from a change of the animals.
     */
    public function up(): void
    {
        Schema::table('batch_weights', function (Blueprint $table) {
            if (!Schema::hasColumn('batch_weights', 'caravans_count')) {
                // Head in the batch at the moment of the record. NULL means unknown,
                // which is the case for every row written before this migration.
                $table->unsignedInteger('caravans_count')->nullable()->after('weight');
            }

            if (!Schema::hasColumn('batch_weights', 'weighed_count')) {
                // How many of those head had a current weight: this is the set the
                // average was computed over, and it may be smaller than the batch.
                $table->unsignedInteger('weighed_count')->nullable()->after('caravans_count');
            }

            if (!Schema::hasColumn('batch_weights', 'total_weight')) {
                // Measured mass: the sum over the weighed animals. Unlike the average,
                // this is additive and conserved when a batch is split, which is what
                // makes a transfer readable as a transfer instead of as a loss.
                $table->decimal('total_weight', 12, 2)->nullable()->after('weighed_count');
            }

            if (!Schema::hasColumn('batch_weights', 'weights_as_of')) {
                // Newest individual weighing behind this point. A row dated today over
                // measurements taken two months ago is not a weighing of today.
                $table->date('weights_as_of')->nullable()->after('total_weight');
            }
        });

        // The average of an empty set is undefined, not zero.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('batch_weights', function (Blueprint $table) {
                $table->decimal('weight', 10, 2)->nullable()->change();
            });
        } else {
            DB::statement('ALTER TABLE batch_weights MODIFY weight DECIMAL(10,2) NULL');
        }
    }

    public function down(): void
    {
        // Rows with an undefined average cannot survive a NOT NULL column.
        DB::table('batch_weights')->whereNull('weight')->delete();

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('batch_weights', function (Blueprint $table) {
                $table->decimal('weight', 10, 2)->nullable(false)->change();
            });
        } else {
            DB::statement('ALTER TABLE batch_weights MODIFY weight DECIMAL(10,2) NOT NULL');
        }

        Schema::table('batch_weights', function (Blueprint $table) {
            $table->dropColumn([
                'caravans_count',
                'weighed_count',
                'total_weight',
                'weights_as_of',
            ]);
        });
    }
};
