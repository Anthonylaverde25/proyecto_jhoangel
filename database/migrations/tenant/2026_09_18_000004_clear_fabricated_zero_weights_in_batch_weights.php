<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data migration: turns fabricated zeros into the undefined value they always were.
     *
     * A batch average of exactly 0.00 kg is never a real measurement. It is either the
     * empty-set case, which the old service coerced to zero, or a batch created without
     * a declared initial weight. Both stated that the animals weigh nothing.
     */
    public function up(): void
    {
        DB::table('batch_weights')
            ->where('weight', 0)
            ->whereIn('type', ['INITIAL', 'CONTROL'])
            ->update(['weight' => null]);

        DB::table('batches')->where('current_weight', 0)->update(['current_weight' => null]);
    }

    /**
     * NOT REVERSIBLE ON PURPOSE.
     *
     * Once the fabricated zeros are null there is no way to tell them apart from the
     * nulls written legitimately by the new code, so restoring them would invent zeros
     * for rows that never had one. The schema migrations are reversible; this one is
     * deliberately separate so that rolling back the schema does not depend on it.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }
};
