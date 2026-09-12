<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-12: extraction act numbers are minted by the system, never typed by an operator.
 * The counter row is locked FOR UPDATE while issuing, so two chutes closing in the same
 * second cannot mint the same number and blow up `dp_company_protocol_unq`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocol_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('series', 10)->comment('Document series, e.g. ACT for extraction acts');
            $table->unsignedSmallInteger('period_year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'series', 'period_year'], 'pns_company_series_year_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_number_sequences');
    }
};
