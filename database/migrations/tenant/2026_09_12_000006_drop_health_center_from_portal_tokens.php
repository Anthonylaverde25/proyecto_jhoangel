<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-29: the grant identifies a PROFESSIONAL. Narrowing it by health centre only made sense
 * while institutions were rows somebody had to pick from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('veterinary_portal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('health_center_id');
        });
    }

    public function down(): void
    {
        Schema::table('veterinary_portal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('health_center_id')->nullable()->constrained('health_centers')->nullOnDelete();
        });
    }
};
