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
            $table->decimal('snapshot_entry_weight', 6, 2)
                ->nullable()
                ->after('female_caravan_id')
                ->comment('Peso vivo registrado al entrar al servicio');

            $table->decimal('snapshot_body_condition', 3, 2)
                ->nullable()
                ->after('snapshot_entry_weight')
                ->comment('Condición corporal pre-servicio (escala 1-5)');

            $table->string('reproductive_status', 30)
                ->default('UNCHECKED')
                ->after('snapshot_body_condition')
                ->comment('UNCHECKED, PREGNANT_HEAD, PREGNANT_BODY, PREGNANT_TAIL, EMPTY');

            $table->index(['service_order_id', 'reproductive_status'], 'idx_sof_order_status');
        });
    }

    public function down(): void
    {
        Schema::table('service_order_females', function (Blueprint $table) {
            $table->dropIndex('idx_sof_order_status');
            $table->dropColumn([
                'snapshot_entry_weight',
                'snapshot_body_condition',
                'reproductive_status',
            ]);
        });
    }
};
