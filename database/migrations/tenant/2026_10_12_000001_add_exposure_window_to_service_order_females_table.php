<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_order_females', function (Blueprint $table) {
            $table->date('exposed_from')
                ->nullable()
                ->after('assigned_male_caravan_id')
                ->comment('Date the female started being exposed; null while the order is DRAFT');

            $table->date('exposed_until')
                ->nullable()
                ->after('exposed_from')
                ->comment('Date the exposure ended (left the batch or bulls withdrawn); null while exposed');

            $table->string('exit_reason', 30)
                ->nullable()
                ->after('exposed_until')
                ->comment('TRANSFERRED_OUT, BULLS_WITHDRAWN, DEATH, SALE');

            $table->boolean('joined_after_start')
                ->default(false)
                ->after('exit_reason')
                ->comment('True when the female entered the batch with the service already running');

            $table->index(['service_order_id', 'exposed_until'], 'idx_sof_order_exposure');
            $table->index(['female_caravan_id', 'exposed_until'], 'idx_sof_female_exposure');
        });
    }

    public function down(): void
    {
        Schema::table('service_order_females', function (Blueprint $table) {
            $table->dropIndex('idx_sof_female_exposure');
            $table->dropIndex('idx_sof_order_exposure');
            $table->dropColumn(['exposed_from', 'exposed_until', 'exit_reason', 'joined_after_start']);
        });
    }
};
