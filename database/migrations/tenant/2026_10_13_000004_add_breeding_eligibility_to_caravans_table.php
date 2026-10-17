<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's decision on whether an animal takes part in breeding. It is independent from the
 * veterinary verdict (bull_health_evaluations): neither dimension writes over the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caravans', function (Blueprint $table) {
            $table->boolean('is_breeding_eligible')->default(true)->after('sex');
            // BreedingExclusionReason code; null while eligible.
            $table->string('breeding_exclusion_reason', 40)->nullable()->after('is_breeding_eligible');
        });

        Schema::create('caravan_breeding_eligibility_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('caravan_id')->constrained('caravans')->cascadeOnDelete();
            $table->boolean('is_breeding_eligible');
            $table->string('reason', 40)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->index(['caravan_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caravan_breeding_eligibility_changes');

        Schema::table('caravans', function (Blueprint $table) {
            $table->dropColumn(['is_breeding_eligible', 'breeding_exclusion_reason']);
        });
    }
};
