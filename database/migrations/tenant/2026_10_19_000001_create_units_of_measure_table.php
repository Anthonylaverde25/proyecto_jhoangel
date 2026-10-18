<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company-owned units of measure (D14). Automatic conversion only between units of the same
 * company and dimension whose factor_to_base is set; a NULL factor marks a unit that never
 * converts (dose, head, service, workday) except through an item presentation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 16);                            // KG, ML, DOSE, ... (English)
            $table->string('name', 64);                            // Spanish display name
            $table->string('symbol', 16);
            $table->string('dimension', 16);                       // MASS | VOLUME | COUNT | TIME | AREA | LENGTH
            $table->decimal('factor_to_base', 20, 10)->nullable(); // NULL = not convertible
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('display_order')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'uq_uom_company_code');
            $table->unique(['id', 'company_id'], 'uq_uom_id_company');
            $table->index(['company_id', 'dimension', 'is_active'], 'idx_uom_company_dimension');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE units_of_measure ADD CONSTRAINT chk_uom_dimension
                CHECK (dimension IN ('MASS','VOLUME','COUNT','TIME','AREA','LENGTH'))");
            DB::statement('ALTER TABLE units_of_measure ADD CONSTRAINT chk_uom_factor_positive
                CHECK (factor_to_base IS NULL OR factor_to_base > 0)');
            DB::statement('ALTER TABLE units_of_measure ADD CONSTRAINT chk_uom_base_factor
                CHECK (is_base = 0 OR factor_to_base = 1)');
        }

        $this->createOneBaseGuard();
    }

    public function down(): void
    {
        Schema::dropIfExists('units_of_measure');
    }

    /**
     * At most one base unit per company and dimension. MySQL: functional unique index
     * (NULL never collides). SQLite: partial unique index.
     */
    private function createOneBaseGuard(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX uq_uom_one_base
                ON units_of_measure (company_id, dimension) WHERE is_base = 1');

            return;
        }

        DB::statement('CREATE UNIQUE INDEX uq_uom_one_base
            ON units_of_measure ((IF(is_base, company_id, NULL)), dimension)');
    }
};
