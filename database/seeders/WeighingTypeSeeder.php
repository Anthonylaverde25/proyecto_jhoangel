<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Enums\WeighingTypeCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The catalog of weighing types, written from WeighingTypeCode so the table never disagrees with
 * the rules. Idempotent: it refreshes the system fields and keeps whatever the tenant decided
 * about is_active.
 */
class WeighingTypeSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        foreach (WeighingTypeCode::cases() as $code) {
            $values = [
                'name' => $code->label(),
                'description' => $code->description(),
                'affects_adpv' => $code->affectsAdpv(),
                'default_method' => $code->defaultMethod()->value,
                'default_shrunk_condition' => $code->defaultShrunkCondition()->value,
                'display_order' => $code->displayOrder(),
                'is_system' => true,
                'updated_at' => $now,
            ];

            $exists = DB::table('weighing_types')->where('code', $code->value)->exists();

            if ($exists) {
                DB::table('weighing_types')->where('code', $code->value)->update($values);
            } else {
                DB::table('weighing_types')->insert($values + [
                    'code' => $code->value,
                    'is_active' => true,
                    'created_at' => $now,
                ]);
            }
        }
    }
}
