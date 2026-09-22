<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TYPES = ['INITIAL', 'CONTROL', 'TRANSFER', 'MOVEMENT_IN', 'MOVEMENT_OUT'];

    /**
     * Splits what used to be a single CONTROL into a genuine weighing and a change of
     * composition. TRANSFER is kept: it is written by the legacy batch-level activity
     * change and there are historical rows carrying it.
     */
    public function up(): void
    {
        $this->setEnum(self::TYPES);
    }

    public function down(): void
    {
        DB::table('batch_weights')
            ->whereIn('type', ['MOVEMENT_IN', 'MOVEMENT_OUT'])
            ->update(['type' => 'CONTROL']);

        $this->setEnum(['INITIAL', 'CONTROL', 'TRANSFER']);
    }

    private function setEnum(array $types): void
    {
        // SQLite stores the enum as a plain string and Laravel emits no CHECK
        // constraint for it, so there is nothing to widen there.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $list = implode(',', array_map(fn (string $t) => "'" . $t . "'", $types));

        DB::statement(
            "ALTER TABLE batch_weights MODIFY type ENUM({$list}) NOT NULL DEFAULT 'CONTROL'"
        );
    }
};
