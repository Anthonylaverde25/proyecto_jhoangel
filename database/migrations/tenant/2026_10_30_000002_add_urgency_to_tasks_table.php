<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Urgency of a task in three levels; existing tasks become not urgent through the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('urgency', 20)->default('not_urgent')->after('description'); // not_urgent | urgent | very_urgent
            $table->index(['company_id', 'urgency'], 'tasks_company_urgency_idx');
            // Target of the company-scoped composite foreign key of task_provider.
            $table->unique(['id', 'company_id'], 'uq_tasks_id_company');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropUnique('uq_tasks_id_company');
            $table->dropIndex('tasks_company_urgency_idx');
            $table->dropColumn('urgency');
        });
    }
};
