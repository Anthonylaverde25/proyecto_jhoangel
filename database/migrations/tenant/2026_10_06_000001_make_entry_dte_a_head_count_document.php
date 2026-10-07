<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A DTE only declares how many head move between establishments. Caravans are no longer loaded
 * with it: they are created when the animals are received, on an ING-03 sheet or by hand, so a
 * purchased caravan exists only once it arrived. What is in transit is a count of head per DTE:
 * declared minus received minus declared missing.
 *
 * down() cannot bring back the caravans that were in transit or missing: up() deletes them,
 * because they never arrived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entry_order_dtes', function (Blueprint $table) {
            // Head of the DTE declared as never arriving, always with a reason (in its incident).
            $table->unsignedInteger('missing_head_count')->default(0)->after('head_count');
        });

        // What used to be listed but not received becomes a count on its DTE.
        DB::table('entry_order_dtes')->update([
            'missing_head_count' => DB::raw("(SELECT COUNT(*) FROM entry_order_animals a
                WHERE a.entry_order_dte_id = entry_order_dtes.id AND a.reception_status = 'MISSING')"),
        ]);

        // Caravans that never arrived have no movement, weight nor body condition: they go.
        $notArrived = DB::table('entry_order_animals')->where('reception_status', '!=', 'RECEIVED')->pluck('caravan_id')->all();
        DB::table('entry_order_animals')->where('reception_status', '!=', 'RECEIVED')->delete();
        foreach (array_chunk($notArrived, 500) as $chunk) {
            DB::table('caravans')->whereIn('id', $chunk)->delete();
        }

        Schema::table('entry_order_animals', function (Blueprint $table) {
            // A row is a caravan received on a DTE; there is nothing else it could be. The caravan's
            // foreign key keeps an index of its own once the composite one goes.
            $table->index('caravan_id');
            $table->dropIndex(['caravan_id', 'reception_status']);
        });

        Schema::table('entry_order_animals', function (Blueprint $table) {
            $table->dropColumn('reception_status');
        });

        Schema::table('entry_order_receipt_sheets', function (Blueprint $table) {
            // Head of the DTE when the sheet was issued: a correction of the DTE leaves it outdated.
            $table->unsignedInteger('dte_head_count')->default(0)->after('weighing_mode');
            // Head pending on the DTE when it was issued, and lines printed (free ones included).
            $table->unsignedSmallInteger('expected_head_count')->default(0)->after('dte_head_count');
            $table->unsignedSmallInteger('row_count')->default(0)->after('expected_head_count');
        });

        foreach (DB::table('entry_order_receipt_sheets')->get(['id', 'entry_order_dte_id', 'caravan_ids', 'page_count']) as $sheet) {
            $listed = count(json_decode((string) $sheet->caravan_ids, true) ?: []);

            DB::table('entry_order_receipt_sheets')->where('id', $sheet->id)->update([
                'dte_head_count' => (int) DB::table('entry_order_dtes')->where('id', $sheet->entry_order_dte_id)->value('head_count'),
                'expected_head_count' => $listed,
                'row_count' => (int) $sheet->page_count * 20,
            ]);
        }

        Schema::table('entry_order_receipt_sheets', function (Blueprint $table) {
            $table->dropColumn('caravan_ids');
        });

        // No DTE lists caravans any more: an animal of more is an arrival excess of its DTE.
        DB::table('entry_order_incidents')->where('type', 'UNLISTED_CARAVAN')->update(['type' => 'ARRIVAL_EXCESS']);
    }

    public function down(): void
    {
        DB::table('entry_order_incidents')->where('type', 'ARRIVAL_EXCESS')->update(['type' => 'UNLISTED_CARAVAN']);

        Schema::table('entry_order_receipt_sheets', function (Blueprint $table) {
            $table->json('caravan_ids')->nullable()->after('weighing_mode');
        });
        DB::table('entry_order_receipt_sheets')->update(['caravan_ids' => '[]']);
        Schema::table('entry_order_receipt_sheets', function (Blueprint $table) {
            $table->dropColumn(['dte_head_count', 'expected_head_count', 'row_count']);
        });

        Schema::table('entry_order_animals', function (Blueprint $table) {
            $table->string('reception_status', 12)->default('RECEIVED')->after('caravan_id');
            $table->index(['caravan_id', 'reception_status']);
        });

        Schema::table('entry_order_animals', function (Blueprint $table) {
            $table->dropIndex(['caravan_id']);
        });

        Schema::table('entry_order_dtes', function (Blueprint $table) {
            $table->dropColumn('missing_head_count');
        });
    }
};
