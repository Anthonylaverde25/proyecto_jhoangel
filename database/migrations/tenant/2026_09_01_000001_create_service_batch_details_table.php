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
        Schema::create('service_batch_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('batch_id')->unique()->constrained('batches')->onDelete('cascade');
            $table->foreignId('female_category_id')->constrained('animal_categories')->onDelete('restrict');
            $table->foreignId('female_subcategory_id')->nullable()->constrained('animal_subcategories')->onDelete('set null');
            $table->foreignId('male_category_id')->constrained('animal_categories')->onDelete('restrict');
            $table->decimal('target_bull_ratio', 5, 2)->nullable()->default(3.00);
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'batch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_batch_details');
    }
};
