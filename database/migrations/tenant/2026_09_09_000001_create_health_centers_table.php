<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue of laboratories and animal health centres (SENASA / RENALAB network)
 * that process preputial scrapes and serology for the pre-service campaign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('name', 150)->comment('Legal or commercial name of the laboratory');
            $table->string('official_code', 50)->nullable()->comment('SENASA / RENALAB official registration code');
            $table->string('phone', 50)->nullable();
            $table->string('email', 100)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active'], 'hc_company_active_idx');
            $table->index(['company_id', 'name'], 'hc_company_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_centers');
    }
};
