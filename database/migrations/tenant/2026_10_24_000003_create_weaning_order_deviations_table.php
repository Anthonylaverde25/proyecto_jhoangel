<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a weaning did differently from its order, one row per calf, and its review. Never
        // blocks: it is written after the fact and only read and reviewed.
        Schema::create('weaning_order_deviations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('weaning_order_id')->constrained('weaning_orders')->onDelete('cascade');
            $table->foreignId('weaning_order_animal_id')->constrained('weaning_order_animals')->onDelete('cascade');
            $table->foreignId('caravan_id')->constrained('caravans')->onDelete('cascade');
            // DESTINATION | WEANING_TYPE | CATEGORY | LATE | NOT_WEANED
            $table->string('kind', 24);
            $table->string('planned', 255)->nullable();
            $table->string('actual', 255)->nullable();
            // Codes behind the labels (batch ids, type, category ids, days late).
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'reviewed_at'], 'wod_company_review_idx');
            $table->index(['weaning_order_id', 'kind'], 'wod_order_kind_idx');
            // One deviation of a kind per calf and order: a re-run never duplicates it.
            $table->unique(['weaning_order_animal_id', 'kind'], 'wod_line_kind_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weaning_order_deviations');
    }
};
