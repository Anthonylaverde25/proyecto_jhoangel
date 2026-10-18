<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two operations (DC15): DELIVERY_RECEIPT, the stock a delivery note brings in, and VALUATION, the
 * value its invoice adds without moving quantities. Both hang from their provider document.
 */
return new class extends Migration
{
    private const TYPES_B2 = "'PURCHASE','INITIAL','CONSUMPTION','COUNT_ADJUSTMENT','RETURN_TO_PROVIDER','REVERSAL'";
    private const TYPES_C = "'PURCHASE','INITIAL','CONSUMPTION','COUNT_ADJUSTMENT','RETURN_TO_PROVIDER','REVERSAL','DELIVERY_RECEIPT','VALUATION'";
    private const FISCAL_B2 = "'PURCHASE','RETURN_TO_PROVIDER'";
    private const FISCAL_C = "'PURCHASE','RETURN_TO_PROVIDER','DELIVERY_RECEIPT','VALUATION'";

    public function up(): void
    {
        $this->replaceChecks(self::TYPES_C, self::FISCAL_C);
    }

    public function down(): void
    {
        $this->replaceChecks(self::TYPES_B2, self::FISCAL_B2);
    }

    private function replaceChecks(string $types, string $fiscal): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (['chk_idoc_type', 'chk_idoc_fiscal_required', 'chk_idoc_fiscal_scope'] as $name) {
            DB::statement("ALTER TABLE item_documents DROP CHECK {$name}");
        }

        DB::statement("ALTER TABLE item_documents ADD CONSTRAINT chk_idoc_type CHECK (type IN ({$types}))");
        DB::statement("ALTER TABLE item_documents ADD CONSTRAINT chk_idoc_fiscal_required
            CHECK (type NOT IN ({$fiscal}) OR fiscal_document_id IS NOT NULL)");
        DB::statement("ALTER TABLE item_documents ADD CONSTRAINT chk_idoc_fiscal_scope
            CHECK (fiscal_document_id IS NULL OR type IN ({$fiscal}, 'REVERSAL'))");
    }
};
