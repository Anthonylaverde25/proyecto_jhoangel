<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The castration of a male as a declared fact. A voided row is kept, so at most one row
        // per caravan has voided_at null; the use case enforces it under a lock.
        Schema::create('caravan_castrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('caravan_id')->constrained('caravans')->cascadeOnDelete();
            $table->date('castrated_on');
            // CastrationMethod code; optional.
            $table->string('method', 20)->nullable();
            $table->string('notes', 500)->nullable();
            // The category the animal had when castrated (TERNERO or TORITO).
            $table->foreignId('from_category_id')->nullable()->constrained('animal_categories')->nullOnDelete();
            $table->foreignId('registered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            // Castrations by period, and the current one of a caravan.
            $table->index(['company_id', 'castrated_on']);
            $table->index(['caravan_id', 'voided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caravan_castrations');
    }
};

