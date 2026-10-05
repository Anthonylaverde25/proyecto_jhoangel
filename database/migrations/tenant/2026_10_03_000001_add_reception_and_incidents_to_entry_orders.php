<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A DTE is loaded before the animals arrive: its caravans exist but are in transit until each one
 * is received, at the chute or by hand. What does not match the purchase (head in excess, animals
 * that will not arrive) never blocks the order: it is raised as an incident to settle with the
 * provider.
 *
 * The DTE keeps no origin of its own: it belongs to the order, whose provider is the owner of the
 * establishment the animals leave from, and that is where the caravans' provenance comes from.
 *
 * down() cannot rebuild PARTIAL: once merged into AWAITING_DTE nothing tells which orders were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entry_order_animals', function (Blueprint $table) {
            // The DTE lists the caravan; whether it arrived is declared on reception.
            $table->string('reception_status', 12)->default('PENDING')->after('caravan_id');   // PENDING | RECEIVED | MISSING
            $table->date('received_at')->nullable()->after('reception_status');
            $table->string('reception_method', 8)->nullable()->after('received_at');           // CHUTE | MANUAL
            $table->foreignId('received_by_user_id')->nullable()->after('reception_method')->constrained('users')->onDelete('set null');

            // What the possession scope asks for every caravan.
            $table->index(['caravan_id', 'reception_status']);
        });

        // Something to settle with the provider. It never blocks the order; it stays open until
        // someone writes down how it was settled.
        Schema::create('entry_order_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('entry_order_id')->constrained('entry_orders')->onDelete('cascade');
            $table->foreignId('entry_order_dte_id')->nullable()->constrained('entry_order_dtes')->onDelete('set null');

            $table->string('type', 16);                      // EXCESS_HEAD | EXCESS_MALES | EXCESS_FEMALES | MISSING_HEAD | MISSING_DTE
            $table->text('detail');                          // the sentence shown, built when it is raised
            $table->json('metadata')->nullable();            // numbers and caravans behind the detail

            $table->string('status', 10)->default('OPEN');   // OPEN | RESOLVED
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('resolved_at')->nullable();

            $table->foreignId('raised_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index('entry_order_id');
        });

        // Data already loaded: until now the DTE was loaded when the animals arrived, so those
        // caravans were received on the DTE's entry day.
        DB::table('entry_order_animals')->update([
            'reception_status' => 'RECEIVED',
            'reception_method' => 'MANUAL',
            'received_at' => DB::raw('(SELECT d.entered_at FROM entry_order_dtes d WHERE d.id = entry_order_animals.entry_order_dte_id)'),
        ]);

        // PARTIAL is no longer a status: it was "waiting for more DTEs", which is AWAITING_DTE now.
        DB::table('entry_orders')->where('status', 'PARTIAL')->update(['status' => 'AWAITING_DTE']);
        DB::table('entry_order_histories')->where('from_status', 'PARTIAL')->update(['from_status' => 'AWAITING_DTE']);
        DB::table('entry_order_histories')->where('to_status', 'PARTIAL')->update(['to_status' => 'AWAITING_DTE']);

        Schema::table('entry_order_dtes', function (Blueprint $table) {
            // Arrival is per caravan now; keeping the DTE's own date would be a second truth.
            $table->dropColumn('entered_at');
        });
    }

    public function down(): void
    {
        Schema::table('entry_order_dtes', function (Blueprint $table) {
            $table->date('entered_at')->nullable()->after('dte_date');
        });

        DB::table('entry_order_dtes')->update([
            'entered_at' => DB::raw('(SELECT MIN(a.received_at) FROM entry_order_animals a WHERE a.entry_order_dte_id = entry_order_dtes.id)'),
        ]);

        DB::table('entry_orders')->where('status', 'IN_TRANSIT')->update(['status' => 'AWAITING_DTE']);

        Schema::dropIfExists('entry_order_incidents');

        Schema::table('entry_order_animals', function (Blueprint $table) {
            $table->dropIndex(['caravan_id', 'reception_status']);
            $table->dropConstrainedForeignId('received_by_user_id');
            $table->dropColumn(['reception_status', 'received_at', 'reception_method']);
        });
    }
};
