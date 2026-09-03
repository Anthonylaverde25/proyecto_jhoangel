<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caravans', function (Blueprint $table) {
            $table->foreignId('provider_id')
                ->nullable()
                ->after('company_id')
                ->constrained('providers')
                ->onDelete('set null');
                
            $table->string('renspa', 50)
                ->default('NO_DEFINIDO')
                ->after('provider_id')
                ->index();
                
            $table->json('provenance_metadata')
                ->nullable()
                ->after('renspa');

            $table->index(['company_id', 'provider_id']);
            $table->index(['company_id', 'renspa']);
        });
    }

    public function down(): void
    {
        Schema::table('caravans', function (Blueprint $table) {
            $table->dropForeign(['provider_id']);
            $table->dropIndex(['company_id', 'provider_id']);
            $table->dropIndex(['company_id', 'renspa']);
            $table->dropIndex(['renspa']);
            $table->dropColumn(['provider_id', 'renspa', 'provenance_metadata']);
        });
    }
};
