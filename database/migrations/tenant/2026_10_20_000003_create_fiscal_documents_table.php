<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provider documents as they are on paper (DS14): invoices, credit notes, tickets. Immutable once
 * registered; the only later change is VOIDED (DS20). Totals are computed at registration and
 * stored, since the document never changes afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('provider_id');
            $table->foreignId('fiscal_document_type_id')->constrained('fiscal_document_types')->restrictOnDelete();
            $table->unsignedInteger('point_of_sale')->nullable();   // 0001 -> 1; NULL only on TICKET
            $table->unsignedBigInteger('number')->nullable();       // 00000123 -> 123; NULL only on TICKET
            $table->string('reference', 64)->nullable();            // free reference of a ticket
            $table->date('issued_at');
            $table->unsignedBigInteger('related_fiscal_document_id')->nullable(); // credit note -> invoice
            $table->decimal('lines_amount', 18, 4);                 // sum of line amounts (net on letter A)
            $table->decimal('line_taxes_amount', 18, 4);            // sum of line taxes
            $table->decimal('document_taxes_amount', 18, 4);        // sum of document taxes
            $table->decimal('total_amount', 18, 4);                 // computed
            $table->decimal('declared_total', 18, 4)->nullable();   // as on paper (DS19)
            $table->string('status', 10)->default('REGISTERED');    // REGISTERED | VOIDED
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id', 'company_id'], 'uq_fdoc_id_company');
            $table->unique(['company_id', 'idempotency_key'], 'uq_fdoc_company_idempotency');
            $table->index(['company_id', 'issued_at'], 'idx_fdoc_company_issued');
            $table->index(['company_id', 'provider_id', 'status'], 'idx_fdoc_company_provider');

            $table->foreign(['provider_id', 'company_id'])           // fiscal_documents_provider_id_company_id_foreign
                ->references(['id', 'company_id'])->on('providers')->restrictOnDelete();
        });

        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->foreign(['related_fiscal_document_id', 'company_id']) // fiscal_documents_related_fiscal_document_id_company_id_foreign
                ->references(['id', 'company_id'])->on('fiscal_documents')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            $checks = [
                'chk_fdoc_status' => "status IN ('REGISTERED','VOIDED')",
                'chk_fdoc_voided' => "(status = 'VOIDED') = (voided_at IS NOT NULL AND void_reason IS NOT NULL)",
                'chk_fdoc_number' => '(point_of_sale IS NULL) = (number IS NULL) AND (point_of_sale IS NULL OR point_of_sale > 0)',
                'chk_fdoc_amounts' => 'lines_amount >= 0 AND line_taxes_amount >= 0 AND document_taxes_amount >= 0
                    AND total_amount >= 0 AND (declared_total IS NULL OR declared_total >= 0)',
            ];
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE fiscal_documents ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }

        $this->createDuplicateGuard();
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');
    }

    /**
     * DS21: one REGISTERED document per (company, provider, type, point of sale, number). A voided one
     * does not count, so a mis-loaded document can be loaded again. NULL numbers (tickets) never
     * collide. MySQL: functional unique index. SQLite: partial unique index.
     */
    private function createDuplicateGuard(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX uq_fdoc_registered_number
                ON fiscal_documents (company_id, provider_id, fiscal_document_type_id, point_of_sale, number)
                WHERE status = 'REGISTERED'");

            return;
        }

        DB::statement("CREATE UNIQUE INDEX uq_fdoc_registered_number
            ON fiscal_documents ((IF(status = 'REGISTERED', company_id, NULL)),
                provider_id, fiscal_document_type_id, point_of_sale, number)");
    }
};
