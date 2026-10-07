<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A DTE declares head, not caravans, and so does its manual reception: the person confirms how
 * many head arrived. Those head are received — no longer in transit — even when their caravans
 * are written later (on an ING-03 or by hand). This counts the head received whose caravan is not
 * known yet; writing a caravan takes one off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entry_order_dtes', function (Blueprint $table) {
            $table->unsignedInteger('uncaravaned_head_count')->default(0)->after('missing_head_count');
        });
    }

    public function down(): void
    {
        Schema::table('entry_order_dtes', function (Blueprint $table) {
            $table->dropColumn('uncaravaned_head_count');
        });
    }
};
