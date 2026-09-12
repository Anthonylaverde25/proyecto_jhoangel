<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-7: gives real substrate to "the vet selects the assigned troop" of Use Case 1.
 * Without it the portal cannot restrict which batches each professional may see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('veterinarian_batch_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('veterinarian_id')->constrained('veterinarians')->onDelete('cascade');
            $table->foreignId('batch_id')->constrained('batches')->onDelete('cascade');
            $table->date('assigned_at');
            $table->date('unassigned_at')->nullable()->comment('Null means the assignment is currently active');
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['veterinarian_id', 'batch_id', 'assigned_at'], 'vba_vet_batch_date_unq');
            $table->index(['company_id', 'veterinarian_id', 'unassigned_at'], 'vba_company_vet_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('veterinarian_batch_assignments');
    }
};
