<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-29 / ADR-38: the professional's file carries identity, never an institution.
 *
 * Storing their health centre here would be the catalogue again under another name, and it goes
 * stale in silence the day they change jobs. What does belong to the person is their CUIT — and
 * the CUIT of the entity they invoice under, when that is not themselves: a vet whose laboratory
 * is an S.R.L. would otherwise be asked to attach a report from their own bench (ADR-35).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('veterinarians', function (Blueprint $table): void {
            $table->string('cuit', 11)->nullable()->after('license_number');
            $table->string('billing_cuit', 11)->nullable()->after('cuit');
        });

        Schema::table('veterinarians', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('health_center_id');
        });
    }

    public function down(): void
    {
        Schema::table('veterinarians', function (Blueprint $table): void {
            $table->foreignId('health_center_id')->nullable()->constrained('health_centers')->nullOnDelete();
            $table->dropColumn(['cuit', 'billing_cuit']);
        });
    }
};
