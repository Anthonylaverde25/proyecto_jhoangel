<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue of acting veterinarians with their professional license (M.P./M.N.).
 *
 * `user_id` stays nullable on purpose: the external professional of Use Case 2
 * (WhatsApp / PDF digitisation) exists in the catalogue without a login account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('veterinarians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Optional link to a login account when the vet uses the portal');
            $table->foreignId('health_center_id')->nullable()->constrained('health_centers')->nullOnDelete();
            $table->string('name', 150);
            $table->string('license_number', 50)->comment('Professional license M.P./M.N. - legal validity key');
            $table->string('accreditation_code', 50)->nullable()->comment('Official accreditation for bovine sanitation');
            $table->string('phone', 50)->nullable()->comment('WhatsApp or mobile contact');
            $table->string('email', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // F9: prevents duplicate professionals created from the quick-create dialog,
            // which would silently fragment a single professional's history.
            $table->unique(['company_id', 'license_number'], 'vet_company_license_unq');
            $table->index(['company_id', 'is_active'], 'vet_company_active_idx');
            $table->index(['company_id', 'user_id'], 'vet_company_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('veterinarians');
    }
};
