<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every change of a caravan's category or subcategory, from -> to, with its cause, the day
        // of the fact and the document behind it. Append-only: a correction is another row.
        Schema::create('caravan_category_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('caravan_id')->constrained('caravans')->cascadeOnDelete();
            $table->foreignId('from_category_id')->nullable()->constrained('animal_categories')->restrictOnDelete();
            $table->foreignId('from_subcategory_id')->nullable()->constrained('animal_subcategories')->restrictOnDelete();
            $table->foreignId('to_category_id')->nullable()->constrained('animal_categories')->restrictOnDelete();
            $table->foreignId('to_subcategory_id')->nullable()->constrained('animal_subcategories')->restrictOnDelete();
            // CategoryChangeCause code.
            $table->string('cause', 40);
            // The day of the fact (castration, birth, order), not the day it was loaded.
            $table->date('occurred_on');
            // The document that caused it: CategoryChangeSource code and its id.
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('registered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // Inventory by period, the history of a caravan, and the changes of one document.
            $table->index(['company_id', 'occurred_on']);
            $table->index(['caravan_id', 'occurred_on']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caravan_category_changes');
    }
};

