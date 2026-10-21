<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caravans', function (Blueprint $table) {
            // A calf born in the system may wait days or weeks for its tag. The unique index stays:
            // several NULLs are allowed by MySQL and SQLite.
            $table->string('identification')->nullable()->change();

            // The day the tag was put on. Null for animals tagged before this column existed.
            $table->date('tagged_at')->nullable()->after('identification');
        });
    }

    public function down(): void
    {
        $untagged = DB::table('caravans')->whereNull('identification')->count();

        if ($untagged > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$untagged} calves have no tag yet. Tag them before rolling back."
            );
        }

        Schema::table('caravans', function (Blueprint $table) {
            $table->dropColumn('tagged_at');
            $table->string('identification')->nullable(false)->change();
        });
    }
};
