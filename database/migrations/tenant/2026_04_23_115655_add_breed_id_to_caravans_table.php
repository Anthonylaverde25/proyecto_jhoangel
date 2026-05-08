<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('caravans', function (Blueprint $table) {
            if (!Schema::hasColumn('caravans', 'breed_id')) {
                $table->foreignId('breed_id')->nullable()->after('breed')->constrained('breeds')->nullOnDelete();
            }
        });

        // Migrar datos históricos de la columna `breed` a `breed_id`
        $caravans = \Illuminate\Support\Facades\DB::table('caravans')->whereNotNull('breed')->get();
        
        foreach ($caravans as $caravan) {
            $breedName = ucfirst(mb_strtolower(trim($caravan->breed)));
            
            // Buscar o crear la raza
            $breedId = \Illuminate\Support\Facades\DB::table('breeds')
                ->where('name', $breedName)
                ->value('id');

            if (!$breedId) {
                $breedId = \Illuminate\Support\Facades\DB::table('breeds')->insertGetId([
                    'name' => $breedName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Actualizar caravana
            \Illuminate\Support\Facades\DB::table('caravans')
                ->where('id', $caravan->id)
                ->update(['breed_id' => $breedId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('caravans', function (Blueprint $table) {
            $table->dropForeign(['breed_id']);
            $table->dropColumn('breed_id');
        });
    }
};
