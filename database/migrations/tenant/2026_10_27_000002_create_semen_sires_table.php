<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The bull a semen comes from (semen S-A, plan part 3). Not a caravan: an external bull (AI centre,
 * stud) or an own bull linked to its existing caravan (SS1, SS2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semen_sires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('name', 100);                               // copied from the caravan when own (SS2)
            $table->string('registry_code', 40)->nullable();           // HBA, centre code ("CH7H")
            $table->unsignedBigInteger('breed_id')->nullable();
            $table->string('stud_name', 100)->nullable();              // origin stud, not the seller
            $table->unsignedBigInteger('caravan_id')->nullable();      // own bull (DA6)
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'company_id'], 'uq_ssire_id_company');
            $table->unique(['company_id', 'registry_code'], 'uq_ssire_company_registry');   // NULLs repeat
            $table->unique(['company_id', 'caravan_id'], 'uq_ssire_company_caravan');       // NULLs repeat
            $table->index(['company_id', 'is_active', 'name'], 'idx_ssire_company_active_name');
            $table->foreign('breed_id')->references('id')->on('breeds')->restrictOnDelete();
            // caravans has no (id, company_id) key: the use case checks the company (SS1).
            $table->foreign('caravan_id')->references('id')->on('caravans')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE semen_sires ADD CONSTRAINT chk_ssire_name CHECK (CHAR_LENGTH(TRIM(name)) > 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('semen_sires');
    }
};
