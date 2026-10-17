<?php

declare(strict_types=1);

use Database\Seeders\WeighingTypeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Classifies the existing weights by the flow that wrote them and then makes the type
     * mandatory: from here on no weight enters the history without its purpose.
     *
     * The catalog is seeded here because the column cannot be required while it is empty.
     */
    public function up(): void
    {
        (new WeighingTypeSeeder())->run();

        $id = static fn (string $code): int => (int) DB::table('weighing_types')->where('code', $code)->value('id');

        // Entry receptions (ING-03) are the only rows that ever wrote a method.
        DB::table('caravan_weights')->whereNull('weighing_type_id')
            ->where(fn ($q) => $q->whereNotNull('method')
                ->orWhere('notes', 'like', '%ingreso%')
                ->orWhere('notes', 'like', '%arrival%')
                ->orWhere('notes', 'like', '%on transfer%'))
            ->update(['weighing_type_id' => $id('ENTRY'), 'source' => 'ENTRY']);

        DB::table('caravan_weights')->whereNull('weighing_type_id')
            ->where('notes', 'like', '%birth%')
            ->update(['weighing_type_id' => $id('BIRTH'), 'source' => 'BIRTH', 'method' => 'PORTABLE_FIELD']);

        DB::table('caravan_weights')->whereNull('weighing_type_id')
            ->where(fn ($q) => $q->where('notes', 'like', '%destete%')->orWhere('notes', 'like', '%weaning%'))
            ->update(['weighing_type_id' => $id('WEANING'), 'source' => 'WEANING']);

        DB::table('caravan_weights')->whereNull('weighing_type_id')
            ->update(['weighing_type_id' => $id('CONTROL'), 'source' => 'MANUAL']);

        DB::table('caravan_weights')->whereNull('method')->update(['method' => 'INDIVIDUAL']);
        DB::table('caravan_weights')->whereNull('source')->update(['source' => 'MANUAL']);

        Schema::table('caravan_weights', function (Blueprint $table) {
            $table->unsignedBigInteger('weighing_type_id')->nullable(false)->change();
            $table->string('method', 16)->default('INDIVIDUAL')->nullable(false)->change();
            $table->string('source', 24)->default('MANUAL')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('caravan_weights', function (Blueprint $table) {
            $table->unsignedBigInteger('weighing_type_id')->nullable()->change();
            $table->string('method', 16)->nullable()->default(null)->change();
            $table->string('source', 24)->nullable()->default(null)->change();
        });
    }
};
