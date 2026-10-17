<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $serviceTypeId = DB::table('batch_types')->where('code', 'SERVICE')->value('id');
        if ($serviceTypeId === null) {
            return;
        }

        $inUse = DB::table('batches')->where('batch_type_id', $serviceTypeId)->exists();
        if ($inUse) {
            throw new RuntimeException(
                "Batches still use batch type SERVICE. Run tenants:migrate-fresh --seed (dev data) before this migration."
            );
        }

        DB::table('company_batch_type')->where('batch_type_id', $serviceTypeId)->delete();
        DB::table('batch_types')->where('id', $serviceTypeId)->delete();
    }

    public function down(): void
    {
        // Catalog rows are owned by BatchTypeSeeder; re-seed to restore a removed type.
    }
};
