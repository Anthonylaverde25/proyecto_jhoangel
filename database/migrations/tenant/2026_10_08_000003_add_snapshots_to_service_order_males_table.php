<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_order_males', function (Blueprint $table) {
            $table->decimal('snapshot_scrotal_circumference', 4, 1)
                ->nullable()
                ->after('male_caravan_id')
                ->comment('Circunferencia escrotal pre-servicio (cm)');

            $table->string('service_capacity', 30)
                ->nullable()
                ->after('snapshot_scrotal_circumference')
                ->comment('Calificación CS: LOW, MEDIUM, HIGH, VERY_HIGH');

            $table->string('status', 30)
                ->default('ACTIVE')
                ->after('service_capacity')
                ->comment('ACTIVE, RETIRED_INJURED, REPLACED, COMPLETED');

            $table->dateTime('retired_at')
                ->nullable()
                ->after('status')
                ->comment('Fecha efectiva de retiro si fue antes del fin de temporada');

            $table->index(['service_order_id', 'status'], 'idx_som_order_status');
        });
    }

    public function down(): void
    {
        Schema::table('service_order_males', function (Blueprint $table) {
            $table->dropIndex('idx_som_order_status');
            $table->dropColumn([
                'snapshot_scrotal_circumference',
                'service_capacity',
                'status',
                'retired_at',
            ]);
        });
    }
};
