<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MAIN was an internal code in B2; with the warehouses view it is shown next to ALM-02 (DD12).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('MAIN', 'ALM-01', defaultOnly: false);
    }

    public function down(): void
    {
        $this->rename('ALM-01', 'MAIN', defaultOnly: true);
    }

    private function rename(string $from, string $to, bool $defaultOnly): void
    {
        $rows = DB::table('warehouses')->where('code', $from)
            ->when($defaultOnly, fn ($q) => $q->where('is_default', true))
            ->get(['id', 'company_id']);

        foreach ($rows as $row) {
            $taken = DB::table('warehouses')->where('company_id', $row->company_id)->where('code', $to)->exists();
            if (!$taken) {
                DB::table('warehouses')->where('id', $row->id)->update(['code' => $to, 'updated_at' => now()]);
            }
        }
    }
};
