<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per female of an AI order (semen S-B): the semen planned for her (optional, IA4) and why she was
 * not inseminated when the service ended (IA12).
 */
return new class extends Migration
{
    private const REASONS = "'NOT_PRESENTED','DEVICE_LOST','DISCARDED','OTHER'";

    public function up(): void
    {
        Schema::table('service_order_females', function (Blueprint $table) {
            $table->unsignedBigInteger('planned_semen_item_id')->nullable()->after('assigned_male_caravan_id');
            $table->string('not_inseminated_reason', 20)->nullable()->after('planned_semen_item_id');
            $table->index(['company_id', 'planned_semen_item_id'], 'idx_sof_planned_semen');
            $table->foreign(['planned_semen_item_id', 'company_id'], 'fk_sof_planned_semen')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE service_order_females ADD CONSTRAINT chk_sof_not_inseminated_reason CHECK (not_inseminated_reason IS NULL OR not_inseminated_reason IN (' . self::REASONS . '))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE service_order_females DROP CHECK chk_sof_not_inseminated_reason');
        }
        Schema::table('service_order_females', function (Blueprint $table) {
            $table->dropForeign('fk_sof_planned_semen');
            $table->dropIndex('idx_sof_planned_semen');
            $table->dropColumn(['planned_semen_item_id', 'not_inseminated_reason']);
        });
    }
};
