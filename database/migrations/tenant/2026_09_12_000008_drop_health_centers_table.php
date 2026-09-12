<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-29: the catalogue existed to guarantee that two records naming the same institution were
 * written the same way. The CUIT guarantees it better, because it travels with the data instead
 * of demanding somebody create a row before a chute session can be closed.
 *
 * Runs last: the previous migrations already released every foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sample_receptions');
        Schema::dropIfExists('health_centers');
    }

    public function down(): void
    {
        Schema::create('health_centers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('name', 150);
            $table->string('official_code', 50)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 100)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('operates_in_portal')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'is_active'], 'hc_company_active_idx');
            $table->index(['company_id', 'name'], 'hc_company_name_idx');
        });
    }
};
