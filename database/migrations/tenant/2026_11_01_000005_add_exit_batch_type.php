<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODE = 'INTERNAL_EXIT';

    /**
     * The terminal batch an animal goes to when it leaves the company (egreso plan v5, P4), like
     * INTERNAL_DEATH. A catalogue row, so tenants created before the seeder knew it get it too.
     */
    public function up(): void
    {
        if (!DB::table('batch_types')->where('code', self::CODE)->exists()) {
            DB::table('batch_types')->insert([
                'code' => self::CODE,
                'name' => 'Internal Exit',
                'description' => 'Lote de los animales que salieron de la empresa (egresos)',
                'icon' => 'heroicons-outline:arrow-right-on-rectangle',
                'color' => '#64748b',
                'activity_id' => DB::table('activities')->where('code', 'INTERNAL')->value('id'),
                'is_active' => true,
                'is_selectable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $typeId = DB::table('batch_types')->where('code', self::CODE)->value('id');

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $enabled = DB::table('company_batch_type')
                ->where('company_id', $companyId)
                ->where('batch_type_id', $typeId)
                ->exists();

            if (!$enabled) {
                DB::table('company_batch_type')->insert([
                    'company_id' => $companyId,
                    'batch_type_id' => $typeId,
                    'is_enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $typeId = DB::table('batch_types')->where('code', self::CODE)->value('id');
        if ($typeId === null) {
            return;
        }

        if (DB::table('batches')->where('batch_type_id', $typeId)->exists()) {
            throw new RuntimeException('Cannot roll back: there are exit batches. Remove the exits first.');
        }

        DB::table('company_batch_type')->where('batch_type_id', $typeId)->delete();
        DB::table('batch_types')->where('id', $typeId)->delete();
    }
};
