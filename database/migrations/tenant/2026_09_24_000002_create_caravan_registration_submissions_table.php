<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per strict registration that went through, written in the same transaction as the
 * animals. A phone that lost the reply resends the same submission_id and gets the original
 * result back instead of a conflict on its own tags.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caravan_registration_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->uuid('submission_id');
            $table->unsignedInteger('registered_count');
            $table->json('registered');
            $table->timestamps();

            $table->unique(['company_id', 'submission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caravan_registration_submissions');
    }
};
