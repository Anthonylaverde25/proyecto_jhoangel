<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The straw profile of a semen item (semen S-A, plan 4.2): one row per item, one item per sire and
 * type (DA2). Collection data lives in the lot, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_semen_details', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->primary();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('semen_sire_id');
            $table->string('semen_type', 16);                         // CONVENTIONAL | SEXED_FEMALE | SEXED_MALE
            $table->string('preservation', 8)->default('FROZEN');      // FROZEN | FRESH
            $table->string('straw_size', 12)->nullable();             // MICRO_0_1 | MINI_0_25 | MEDIUM_0_5
            $table->string('straw_color', 12)->nullable();            // StrawColor
            $table->string('cap_color', 12)->nullable();              // StrawColor
            $table->timestamps();

            $table->unique(['company_id', 'semen_sire_id', 'semen_type'], 'uq_isd_company_sire_type');  // DA2
            $table->foreign(['item_id', 'company_id'])                // item_semen_details_item_id_company_id_foreign
                ->references(['id', 'company_id'])->on('items')->cascadeOnDelete();
            $table->foreign(['semen_sire_id', 'company_id'])          // item_semen_details_semen_sire_id_company_id_foreign
                ->references(['id', 'company_id'])->on('semen_sires')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            $colors = "'TRANSPARENT','WHITE','YELLOW','ORANGE','RED','PINK','PURPLE','BLUE','GREEN','GRAY','BROWN','BLACK'";
            $checks = [
                'chk_isd_type' => "semen_type IN ('CONVENTIONAL','SEXED_FEMALE','SEXED_MALE')",
                'chk_isd_preservation' => "preservation IN ('FROZEN','FRESH')",
                'chk_isd_size' => "straw_size IS NULL OR straw_size IN ('MICRO_0_1','MINI_0_25','MEDIUM_0_5')",
                'chk_isd_colors' => "(straw_color IS NULL OR straw_color IN ({$colors})) AND (cap_color IS NULL OR cap_color IN ({$colors}))",
            ];
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE item_semen_details ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_semen_details');
    }
};
