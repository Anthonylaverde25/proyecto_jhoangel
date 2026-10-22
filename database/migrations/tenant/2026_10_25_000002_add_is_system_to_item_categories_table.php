<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the base categories as system categories (the software relies on them: no rename, move or
 * deactivation) and gives every company the missing FEED ("Alimentos") root. The data is frozen
 * here on purpose, like M10. A root the company already named "Alimentos" is promoted instead of
 * duplicated.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const SYSTEM_CODES = [
        'HEALTH', 'NUTRITION', 'REPRODUCTION', 'IDENTIFICATION', 'FUEL',
        'MAINTENANCE', 'FREIGHT', 'VET_SERVICES', 'FIELD_SERVICES',
    ];

    private const FEED_CODE = 'FEED';
    private const FEED_NAME = 'Alimentos';
    private const FEED_ORDER = 2;

    public function up(): void
    {
        Schema::table('item_categories', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('display_order');
        });

        $now = now();

        DB::table('item_categories')
            ->whereIn('code', self::SYSTEM_CODES)
            ->whereNull('parent_id')
            ->update(['is_system' => true, 'is_active' => true, 'updated_at' => $now]);

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $this->ensureFeed((int) $companyId, $now);
        }
    }

    public function down(): void
    {
        // FEED rows stay: items may already hang from them, and they are valid ordinary categories.
        Schema::table('item_categories', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }

    private function ensureFeed(int $companyId, mixed $now): void
    {
        $existing = DB::table('item_categories')
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q
                ->where('code', self::FEED_CODE)
                ->orWhere(fn ($q) => $q->whereNull('parent_id')->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(self::FEED_NAME)])))
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [self::FEED_CODE])
            ->first();

        if ($existing !== null) {
            DB::table('item_categories')->where('id', $existing->id)->update([
                'parent_id' => null, 'code' => self::FEED_CODE, 'name' => self::FEED_NAME,
                'is_active' => true, 'is_system' => true, 'updated_at' => $now,
            ]);

            return;
        }

        DB::table('item_categories')->insert([
            'company_id' => $companyId, 'parent_id' => null, 'code' => self::FEED_CODE, 'name' => self::FEED_NAME,
            'is_active' => true, 'display_order' => self::FEED_ORDER, 'is_system' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
};
