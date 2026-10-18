<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Delivery notes are loaded from this delivery on (DS22 → DC13). Their row keeps carries_amounts =
 * false: a delivery note has no taxes nor totals of its own, and its prices are optional (DC14).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('fiscal_document_types')
            ->where('code', 'DELIVERY_NOTE')
            ->update(['is_active' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('fiscal_document_types')
            ->where('code', 'DELIVERY_NOTE')
            ->update(['is_active' => false, 'updated_at' => now()]);
    }
};
