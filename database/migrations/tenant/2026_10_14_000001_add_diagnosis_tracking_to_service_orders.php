<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A service order is closed by its pregnancy diagnosis, not by the bull withdrawal.
 *
 * Each female keeps her CURRENT verdict for the order (the one that counts to close it and for
 * the pregnancy rate), with the day it was given, who loaded it and the gestation it opened. The
 * order keeps the day its diagnosis was closed and, when the service went on with the empty
 * females, the order it continues.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->date('diagnosis_completed_at')
                ->nullable()
                ->after('actual_end_date')
                ->comment('Day the pregnancy diagnosis was closed (status -> SUCCESS)');

            $table->foreignId('parent_service_order_id')
                ->nullable()
                ->after('batch_id')
                ->constrained('service_orders', 'id', 'fk_so_parent_order')
                ->nullOnDelete()
                ->comment('Order this one continues: the bulls stayed with the empty females');
        });

        $this->restoreOneActivePerBatchGuard();

        Schema::table('service_order_females', function (Blueprint $table) {
            $table->date('diagnosed_at')
                ->nullable()
                ->after('reproductive_status')
                ->comment('Day of the diagnosis that set the current verdict');

            $table->foreignId('diagnosed_by_user_id')
                ->nullable()
                ->after('diagnosed_at')
                ->constrained('users', 'id', 'fk_sof_diagnosed_by')
                ->nullOnDelete();

            $table->foreignId('gestation_id')
                ->nullable()
                ->after('diagnosed_by_user_id')
                ->constrained('caravan_gestations', 'id', 'fk_sof_gestation')
                ->nullOnDelete()
                ->comment('Gestation opened by a PREGNANT verdict of this order');

            $table->string('not_diagnosed_reason', 30)
                ->nullable()
                ->after('gestation_id')
                ->comment('NOT_GATHERED, NOT_FOUND, OTHER (only when NOT_DIAGNOSED)');

            $table->string('not_diagnosed_notes', 255)
                ->nullable()
                ->after('not_diagnosed_reason');
        });

        // The verdict no longer carries the stage: it lives in the gestation, and is optional.
        DB::table('service_order_females')
            ->whereIn('reproductive_status', ['PREGNANT_HEAD', 'PREGNANT_BODY', 'PREGNANT_TAIL'])
            ->update(['reproductive_status' => 'PREGNANT']);

        // A female that died or was sold before any verdict will never be diagnosed.
        DB::table('service_order_females')
            ->where('reproductive_status', 'UNCHECKED')
            ->whereIn('exit_reason', ['DEATH', 'SALE'])
            ->update(['reproductive_status' => 'EXITED']);

        // Orders "closed" by the bull withdrawal while females were still unchecked go back to
        // waiting for their diagnosis.
        DB::table('service_orders')
            ->where('status', 'SUCCESS')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('service_order_females')
                    ->whereColumn('service_order_females.service_order_id', 'service_orders.id')
                    ->where('service_order_females.reproductive_status', 'UNCHECKED');
            })
            ->update(['status' => 'AWAITING_DIAGNOSIS', 'final_pregnancy_rate' => null]);
    }

    public function down(): void
    {
        DB::table('service_orders')
            ->where('status', 'AWAITING_DIAGNOSIS')
            ->update(['status' => 'SUCCESS']);

        DB::table('service_order_females')
            ->whereIn('reproductive_status', ['EXITED', 'NOT_DIAGNOSED'])
            ->update(['reproductive_status' => 'UNCHECKED']);

        Schema::table('service_order_females', function (Blueprint $table) {
            $table->dropForeign('fk_sof_gestation');
            $table->dropForeign('fk_sof_diagnosed_by');
            $table->dropColumn([
                'diagnosed_at',
                'diagnosed_by_user_id',
                'gestation_id',
                'not_diagnosed_reason',
                'not_diagnosed_notes',
            ]);
        });

        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropForeign('fk_so_parent_order');
            $table->dropColumn(['diagnosis_completed_at', 'parent_service_order_id']);
        });

        $this->restoreOneActivePerBatchGuard();
    }

    /**
     * SQLite rebuilds the table to add a foreign key and recreates its indexes without their
     * WHERE clause: the partial "one active service per batch" index would become a plain unique
     * index over batch_id. It is put back as it was. MySQL keeps its generated-column guard.
     */
    private function restoreOneActivePerBatchGuard(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS uq_so_one_active_per_batch');
        DB::statement(
            "CREATE UNIQUE INDEX uq_so_one_active_per_batch ON service_orders (batch_id) WHERE status IN ('DRAFT', 'APPROVED')"
        );
    }
};
