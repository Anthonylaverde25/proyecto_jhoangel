<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A general average weighing states one average per category: in a mixed batch a single figure
     * would falsify a calf with the weight of a cow. One order keeps the weighing as one document
     * and one point on the curve of the batch.
     */
    public function up(): void
    {
        Schema::create('weighing_order_category_averages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('weighing_order_id')->constrained('weighing_orders')->onDelete('cascade');

            // Null: the animals of the batch with no category on record, weighed as their own group.
            $table->foreignId('category_id')->nullable()->constrained('animal_categories')->restrictOnDelete();
            $table->unsignedInteger('head_count');
            $table->decimal('average_weight', 8, 2);
            $table->timestamps();

            $table->unique(['weighing_order_id', 'category_id'], 'wo_category_averages_order_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weighing_order_category_averages');
    }
};
