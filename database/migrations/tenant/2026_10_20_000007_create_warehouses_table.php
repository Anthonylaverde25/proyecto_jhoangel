<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Physical storage places of a company (D8). Every company has exactly one default warehouse
 * (MAIN): at most one by index here, at least one by the company bootstrap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 32);                            // MAIN for the default one
            $table->string('name', 100);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'uq_wh_company_code');
            $table->unique(['company_id', 'name'], 'uq_wh_company_name');
            $table->unique(['id', 'company_id'], 'uq_wh_id_company');
        });

        $this->createOneDefaultGuard();
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }

    /** At most one default per company. MySQL: functional unique index. SQLite: partial unique index. */
    private function createOneDefaultGuard(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX uq_wh_one_default ON warehouses (company_id) WHERE is_default = 1');

            return;
        }

        DB::statement('CREATE UNIQUE INDEX uq_wh_one_default ON warehouses ((IF(is_default, company_id, NULL)))');
    }
};
