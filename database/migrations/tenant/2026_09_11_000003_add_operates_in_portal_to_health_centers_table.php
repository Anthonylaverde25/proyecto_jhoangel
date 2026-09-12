<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-24: the catalogue defines the custody mode.
 *
 * A laboratory that will never use the portal has no counterparty able to register a reception,
 * and pretending otherwise is what leaves the chain of custody unauditable. This single flag is
 * the cut the whole design hangs off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_centers', function (Blueprint $table): void {
            $table->boolean('operates_in_portal')->default(false)->after('is_active')
                ->comment('Whether this institution operates inside the system (ADR-24)');
        });

        // An institution with at least one professional holding a system user is already
        // operating inside the portal. Everything else is assumed external until the office
        // reviews it by hand: assuming the opposite would silently promise a counterparty that
        // does not exist.
        $operating = DB::table('veterinarians')
            ->whereNotNull('user_id')
            ->whereNotNull('health_center_id')
            ->distinct()
            ->pluck('health_center_id')
            ->all();

        if ($operating !== []) {
            DB::table('health_centers')->whereIn('id', $operating)->update(['operates_in_portal' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('health_centers', function (Blueprint $table): void {
            $table->dropColumn('operates_in_portal');
        });
    }
};
