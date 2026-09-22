<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Makes the management system of a batch a three-state fact.
     *
     * `is_confined` was born as `boolean NOT NULL DEFAULT false` while the declaration
     * was only demanded in Recría. That worked because the activity itself told whether
     * a `false` was an answer or the column default, and the frontend encoded exactly
     * that rule in `declaresManagementSystem()`.
     *
     * The management system is now asked for in every productive activity: a Cría batch
     * can be penned just like a Recría one. The activity can no longer stand in for the
     * missing state, so the column has to say it itself:
     *
     *   true  -> penned (corral), declared
     *   false -> pasture (extensivo), declared
     *   null  -> nobody has been asked yet
     *
     * Without the null, every pre-existing batch outside Recría would start asserting
     * "a campo" on a value no producer ever stated.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('batches', 'is_confined')) {
            return;
        }

        Schema::table('batches', function (Blueprint $table) {
            $table->boolean('is_confined')->nullable()->default(null)->change();
        });

        // Backfill: which of today's `false` values were an answer, and which are the
        // default? That question already has an answer running in production, the one
        // `declaresManagementSystem()` uses to decide what to paint on screen. It is
        // applied here verbatim rather than inventing a second criterion:
        //
        //   - a `true` is always a declaration: nothing sets it implicitly, never touched
        //   - a `false` in Recría is an answer: the form demanded it, kept
        //   - anything else was the column default: becomes null
        $recriaIds = DB::table('activities')->where('code', 'RECRIA')->pluck('id')->all();

        DB::table('batches')
            ->where('is_confined', false)
            ->where(function ($query) use ($recriaIds) {
                $query->whereNull('activity_id');

                if ($recriaIds !== []) {
                    $query->orWhereNotIn('activity_id', $recriaIds);
                }
            })
            ->update(['is_confined' => null]);
    }

    /**
     * Reverting collapses the three states back into two: every undeclared batch is
     * read as pasture again, which is the ambiguity this migration removed.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('batches', 'is_confined')) {
            return;
        }

        DB::table('batches')->whereNull('is_confined')->update(['is_confined' => false]);

        Schema::table('batches', function (Blueprint $table) {
            $table->boolean('is_confined')->default(false)->nullable(false)->change();
        });
    }
};
