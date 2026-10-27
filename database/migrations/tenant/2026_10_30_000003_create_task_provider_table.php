<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Providers a task concerns (many to many). The composite foreign keys keep both ends in the
 * same company, as item_provider does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_provider', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('provider_id');
            $table->timestamps();

            $table->unique(['task_id', 'provider_id'], 'uq_tprov_task_provider');
            $table->index(['company_id', 'provider_id'], 'idx_tprov_company_provider');

            $table->foreign(['task_id', 'company_id'])
                ->references(['id', 'company_id'])->on('tasks')
                ->cascadeOnDelete();
            $table->foreign(['provider_id', 'company_id'])
                ->references(['id', 'company_id'])->on('providers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_provider');
    }
};
