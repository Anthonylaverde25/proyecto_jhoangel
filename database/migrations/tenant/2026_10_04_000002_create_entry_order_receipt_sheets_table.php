<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ING-03 receipt sheets: the paper a DTE is received on, an appendix of the order's ING-02. Every
 * sheet issued is recorded — which DTE, which caravans it printed, how many pages, who and when —
 * so the system knows which paper went out to the chute and has not come back. Issuing a new
 * sheet for the same DTE replaces the previous one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_order_receipt_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('entry_order_id')->constrained('entry_orders')->onDelete('cascade');
            $table->foreignId('entry_order_dte_id')->constrained('entry_order_dtes')->onDelete('cascade');

            // Correlative within the order: R1, R2… what the paper prints next to the order code.
            $table->unsignedInteger('number');

            // ISSUED | PARTIAL | PROCESSED | REPLACED
            $table->string('status', 12)->default('ISSUED');

            // The caravans in transit when it was issued, in print order: what each page lists.
            $table->json('caravan_ids');
            $table->unsignedSmallInteger('page_count');

            // Page numbers already scanned and received.
            $table->json('processed_pages')->nullable();

            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamps();

            $table->unique(['entry_order_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_order_receipt_sheets');
    }
};
