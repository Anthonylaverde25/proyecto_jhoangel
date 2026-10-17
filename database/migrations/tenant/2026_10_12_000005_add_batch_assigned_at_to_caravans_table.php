<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a caravan joined its current batch. It drives the "recently arrived" list of a batch:
 * neither created_at (the animal is older than its batch) nor the movements (not every path
 * records the destination batch) can answer it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caravans', function (Blueprint $table) {
            $table->timestamp('batch_assigned_at')->nullable()->after('batch_id');
            $table->index(['batch_id', 'batch_assigned_at'], 'caravans_batch_assigned_idx');
        });

        // Backfill: the last movement into the current batch, else the entry date, else the creation date.
        DB::table('caravans')
            ->whereNotNull('batch_id')
            ->select(['id', 'batch_id', 'entry_date', 'created_at'])
            ->chunkById(500, function ($caravans): void {
                foreach ($caravans as $caravan) {
                    $lastEntry = DB::table('caravan_movements')
                        ->where('caravan_id', $caravan->id)
                        ->where('to_batch_id', $caravan->batch_id)
                        ->max('movement_date');

                    DB::table('caravans')
                        ->where('id', $caravan->id)
                        ->update(['batch_assigned_at' => $lastEntry ?? $caravan->entry_date ?? $caravan->created_at]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('caravans', function (Blueprint $table) {
            $table->dropIndex('caravans_batch_assigned_idx');
            $table->dropColumn('batch_assigned_at');
        });
    }
};
