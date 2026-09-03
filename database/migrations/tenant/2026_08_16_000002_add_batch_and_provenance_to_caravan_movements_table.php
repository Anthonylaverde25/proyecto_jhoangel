<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caravan_movements', function (Blueprint $table) {
            $table->foreignId('from_batch_id')
                ->nullable()
                ->after('type')
                ->constrained('batches')
                ->onDelete('set null');

            $table->foreignId('to_batch_id')
                ->nullable()
                ->after('from_batch_id')
                ->constrained('batches')
                ->onDelete('set null');

            $table->foreignId('provider_id')
                ->nullable()
                ->after('to_batch_id')
                ->constrained('providers')
                ->onDelete('set null');

            $table->string('from_renspa', 50)
                ->default('NO_DEFINIDO')
                ->after('provider_id');

            $table->json('provenance_metadata')
                ->nullable()
                ->after('from_renspa');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE caravan_movements MODIFY COLUMN type ENUM('ORIGIN','ENTRY','EXIT','TRANSFER','WEANING','PURCHASE') DEFAULT 'ENTRY'");
        }
    }

    public function down(): void
    {
        Schema::table('caravan_movements', function (Blueprint $table) {
            $table->dropForeign(['from_batch_id']);
            $table->dropForeign(['to_batch_id']);
            $table->dropForeign(['provider_id']);
            $table->dropColumn([
                'from_batch_id',
                'to_batch_id',
                'provider_id',
                'from_renspa',
                'provenance_metadata'
            ]);
        });
    }
};
