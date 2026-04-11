<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('caravans', function (Blueprint $table) {
            $table->id();
            
            // Identificación única (Número de Caravana)
            $table->unsignedInteger('identification')->unique();
            
            // Categoría del animal
            $table->enum('category', [
                'novillito',
                'novillo',
                'vaquillona',
                'vaca',
                'vaca_vacia',
                'ternero',
                'toro'
            ]);
            
            // Cantidad de dientes (Máximo 2 dígitos: 0-99)
            $table->unsignedTinyInteger('teeth')->default(0);
            
            // Pesos en Kg (Precisión decimal: 8 dígitos totales, 2 decimales)
            $table->decimal('entry_weight', 8, 2)->nullable();
            $table->decimal('exit_weight', 8, 2)->nullable();
            
            $table->timestamps();

            // Índices de búsqueda
            $table->index(['category', 'teeth']);
        });

        // Restricción técnica: teeth debe estar entre 0 y 99
        DB::statement('ALTER TABLE caravans ADD CONSTRAINT check_teeth_range CHECK (teeth >= 0 AND teeth <= 99)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('caravans');
    }
};
