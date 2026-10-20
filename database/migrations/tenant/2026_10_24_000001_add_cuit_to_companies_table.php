<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's own CUIT: the receiver of the documents its providers issue. Digits only, nullable
 * (existing companies have none) and NOT unique: one owner may run several farms (companies)
 * under the same CUIT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('cuit', 11)->nullable()->after('renspa');
            $table->index('cuit', 'idx_companies_cuit');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex('idx_companies_cuit');
            $table->dropColumn('cuit');
        });
    }
};
