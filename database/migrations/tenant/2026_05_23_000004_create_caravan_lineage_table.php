<?php

declare(strict_types=1);

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
        Schema::create('caravan_lineage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caravan_id')->unique()->constrained('caravans')->cascadeOnDelete();
            $table->foreignId('mother_id')->constrained('caravans')->cascadeOnDelete();
            $table->foreignId('father_id')->nullable()->constrained('caravans')->nullOnDelete();
            $table->foreignId('gestation_id')->nullable()->constrained('caravan_gestations')->nullOnDelete();
            $table->date('birth_date');
            $table->boolean('is_nursing')->default(true);
            $table->timestamps();

            $table->index('mother_id');
            $table->index('father_id');
            $table->index('gestation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('caravan_lineage');
    }
};
