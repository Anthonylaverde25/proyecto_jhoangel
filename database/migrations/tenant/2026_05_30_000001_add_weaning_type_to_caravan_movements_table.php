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
        if (DB::getDriverName() === 'sqlite') {
            // Recreate table to update CHECK constraint in SQLite
            Schema::rename('caravan_movements', 'caravan_movements_old');

            Schema::create('caravan_movements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('caravan_id')->constrained()->onDelete('cascade');
                $table->foreignId('company_id')->constrained()->onDelete('cascade');
                $table->string('renspa');
                $table->enum('type', ['ORIGIN', 'ENTRY', 'EXIT', 'TRANSFER', 'WEANING'])->default('ENTRY');
                $table->dateTime('movement_date');
                $table->text('observations')->nullable();
                $table->timestamps();
            });

            DB::statement('INSERT INTO caravan_movements SELECT * FROM caravan_movements_old');
            Schema::dropIfExists('caravan_movements_old');
            return;
        }

        DB::statement("ALTER TABLE caravan_movements MODIFY COLUMN type ENUM('ORIGIN','ENTRY','EXIT','TRANSFER','WEANING') DEFAULT 'ENTRY'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::rename('caravan_movements', 'caravan_movements_old');

            Schema::create('caravan_movements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('caravan_id')->constrained()->onDelete('cascade');
                $table->foreignId('company_id')->constrained()->onDelete('cascade');
                $table->string('renspa');
                $table->enum('type', ['ORIGIN', 'ENTRY', 'EXIT', 'TRANSFER'])->default('ENTRY');
                $table->dateTime('movement_date');
                $table->text('observations')->nullable();
                $table->timestamps();
            });

            DB::statement('INSERT INTO caravan_movements SELECT * FROM caravan_movements_old');
            Schema::dropIfExists('caravan_movements_old');
            return;
        }

        DB::statement("ALTER TABLE caravan_movements MODIFY COLUMN type ENUM('ORIGIN','ENTRY','EXIT','TRANSFER') DEFAULT 'ENTRY'");
    }
};
