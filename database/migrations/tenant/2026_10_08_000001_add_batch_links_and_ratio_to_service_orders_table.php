<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->foreignId('origin_batch_id')
                ->nullable()
                ->after('company_id')
                ->constrained('batches')
                ->onDelete('restrict')
                ->comment('Lote perenne de origen de los vientres (ej. Lote 1)');

            $table->foreignId('service_batch_id')
                ->nullable()
                ->after('origin_batch_id')
                ->constrained('batches')
                ->onDelete('restrict')
                ->comment('Lote de servicio temporal generado en la tabla batches');

            $table->decimal('target_bull_ratio', 5, 2)
                ->nullable()
                ->default(3.00)
                ->after('service_type')
                ->comment('Ratio objetivo toro/vaca (ej. 3.00% tradicional)');

            $table->date('planned_end_date')
                ->nullable()
                ->after('planned_start_date')
                ->comment('Fecha estimada de retiro de toros (aprox. 90 días)');

            $table->decimal('final_pregnancy_rate', 5, 2)
                ->nullable()
                ->after('target_bull_ratio')
                ->comment('Porcentaje de preñez final consolidado post-tacto');

            $table->index(['company_id', 'origin_batch_id', 'status'], 'idx_so_origin_batch_status');
            $table->index(['company_id', 'service_batch_id', 'status'], 'idx_so_service_batch_status');
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropForeign(['origin_batch_id']);
            $table->dropForeign(['service_batch_id']);
            $table->dropIndex('idx_so_origin_batch_status');
            $table->dropIndex('idx_so_service_batch_status');
            $table->dropColumn([
                'origin_batch_id',
                'service_batch_id',
                'target_bull_ratio',
                'planned_end_date',
                'final_pregnancy_rate',
            ]);
        });
    }
};
