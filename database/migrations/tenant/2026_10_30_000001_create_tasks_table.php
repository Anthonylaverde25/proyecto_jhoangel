<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks of a company, recorded from the web, the assistant or an MCP client.
 * Step 1 keeps only the text; status, due date and assignee come later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->text('description');
            $table->timestamps();

            $table->index(['company_id', 'created_at'], 'tasks_company_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
