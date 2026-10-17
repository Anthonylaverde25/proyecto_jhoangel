<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The new index first: the company_id foreign key needs an index led by company_id, and
        // the two being dropped are the only ones it has (MySQL error 1553 otherwise).
        Schema::table('service_orders', function (Blueprint $table) {
            $table->index(['company_id', 'batch_id', 'status'], 'idx_so_batch_status');
        });

        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropForeign(['origin_batch_id']);
            $table->dropForeign(['service_batch_id']);
        });

        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropIndex('idx_so_origin_batch_status');
            $table->dropIndex('idx_so_service_batch_status');
            $table->dropColumn(['origin_batch_id', 'service_batch_id']);
        });

        // Service history must survive the batch: restrict instead of cascade (F10).
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
        });
        Schema::table('service_orders', function (Blueprint $table) {
            $table->foreign('batch_id')->references('id')->on('batches')->onDelete('restrict');
        });

        $this->createOneActivePerBatchGuard();
    }

    /**
     * At most one DRAFT/APPROVED order per batch (F9). SQLite supports partial unique indexes;
     * MySQL does not, so there a stored generated column that is NULL for closed orders is made
     * unique instead (NULLs never collide).
     */
    private function createOneActivePerBatchGuard(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX uq_so_one_active_per_batch ON service_orders (batch_id) WHERE status IN ('DRAFT', 'APPROVED')"
            );

            return;
        }

        Schema::table('service_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('active_batch_guard')
                ->nullable()
                ->storedAs("CASE WHEN status IN ('DRAFT', 'APPROVED') THEN batch_id END")
                ->comment('Equals batch_id while the order is active; enforces one active service per batch');
            $table->unique('active_batch_guard', 'uq_so_one_active_per_batch');
        });
    }

    private function dropOneActivePerBatchGuard(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS uq_so_one_active_per_batch');

            return;
        }

        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropUnique('uq_so_one_active_per_batch');
            $table->dropColumn('active_batch_guard');
        });
    }

    public function down(): void
    {
        $this->dropOneActivePerBatchGuard();

        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
        });
        Schema::table('service_orders', function (Blueprint $table) {
            $table->foreign('batch_id')->references('id')->on('batches')->onDelete('cascade');

            $table->foreignId('origin_batch_id')->nullable()->after('company_id')
                ->constrained('batches')->onDelete('restrict');
            $table->foreignId('service_batch_id')->nullable()->after('origin_batch_id')
                ->constrained('batches')->onDelete('restrict');
            $table->index(['company_id', 'origin_batch_id', 'status'], 'idx_so_origin_batch_status');
            $table->index(['company_id', 'service_batch_id', 'status'], 'idx_so_service_batch_status');
        });

        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropIndex('idx_so_batch_status');
        });

        DB::table('service_orders')->update(['origin_batch_id' => DB::raw('batch_id')]);
    }
};
