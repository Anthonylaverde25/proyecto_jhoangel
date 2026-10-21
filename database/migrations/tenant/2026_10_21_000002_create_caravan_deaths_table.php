<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The death of an animal as a declared fact: when, why, and where it went.
        Schema::create('caravan_deaths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // An animal dies once.
            $table->foreignId('caravan_id')->unique()->constrained('caravans')->cascadeOnDelete();
            $table->date('death_date');
            $table->foreignId('death_cause_id')->constrained('death_causes')->restrictOnDelete();
            $table->text('observations')->nullable();
            // Where it was when it died, and the death batch it went to.
            $table->foreignId('from_batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->foreignId('death_batch_id')->constrained('batches')->restrictOnDelete();
            $table->foreignId('caravan_movement_id')->nullable()->constrained('caravan_movements')->nullOnDelete();
            $table->foreignId('registered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Mortality by period and by cause.
            $table->index(['company_id', 'death_date']);
            $table->index(['company_id', 'death_cause_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caravan_deaths');
    }
};
