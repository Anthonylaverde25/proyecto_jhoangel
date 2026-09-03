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
        // 1. Agregar company_id y permitir provider_id nullable en farms
        Schema::table('farms', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->onDelete('cascade');
            $table->foreignId('provider_id')->nullable()->change();
        });


        // 2. Backfill: Crear Finca Propia Principal para empresas existentes que no tengan una
        $companies = DB::table('companies')->get();
        foreach ($companies as $company) {
            DB::table('farms')->whereNull('company_id')->update(['company_id' => $company->id]);

            $ownFarmId = DB::table('farms')
                ->where('company_id', $company->id)
                ->whereNull('provider_id')
                ->value('id');

            if (!$ownFarmId) {
                $ownFarmId = DB::table('farms')->insertGetId([
                    'company_id' => $company->id,
                    'name' => $company->name . ' (Principal)',
                    'renspa' => $company->renspa ?? 'NO_DEFINIDO',
                    'location' => $company->location ?? 'Establecimiento Principal',
                    'provider_id' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Asignar lotes propios que tenían farm_id = NULL hacia la Finca Propia Principal
            DB::table('batches')
                ->where('company_id', $company->id)
                ->whereNull('farm_id')
                ->update(['farm_id' => $ownFarmId]);
        }
    }

    public function down(): void
    {
        Schema::table('farms', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn(['company_id']);
        });
    }
};
