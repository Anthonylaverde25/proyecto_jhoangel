<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The DTE is recommended, never required: a row of entry_order_dtes without number nor date is a
 * group of head moving without the document in hand. The paper is written on the same row later,
 * so its receptions, caravans and ING-03 sheets stay where they are. The unique index still rules
 * over the numbers: NULLs do not collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entry_order_dtes', function (Blueprint $table) {
            $table->string('dte_number', 40)->nullable()->change();
            $table->date('dte_date')->nullable()->change();
        });
    }

    /**
     * Going back needs every row to have a document: the ones without get a placeholder.
     */
    public function down(): void
    {
        DB::table('entry_order_dtes')->whereNull('dte_number')->orderBy('id')->each(function (object $row): void {
            DB::table('entry_order_dtes')->where('id', $row->id)->update(['dte_number' => "SIN-DTE-{$row->id}"]);
        });
        DB::table('entry_order_dtes')->whereNull('dte_date')->update(['dte_date' => DB::raw('DATE(created_at)')]);

        Schema::table('entry_order_dtes', function (Blueprint $table) {
            $table->string('dte_number', 40)->nullable(false)->change();
            $table->date('dte_date')->nullable(false)->change();
        });
    }
};
