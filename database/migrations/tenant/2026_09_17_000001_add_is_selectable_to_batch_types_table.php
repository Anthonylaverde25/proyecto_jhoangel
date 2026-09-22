<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Visibility of a batch type in the manual selectors.
     *
     * Some types are never picked by hand: they are created by dedicated paths of the
     * system. Until now they stayed out of sight only because no selector showed the
     * batch type at all, which stopped being true once the type became a visible field
     * of the batch creation form.
     */
    public function up(): void
    {
        if (Schema::hasColumn('batch_types', 'is_selectable')) {
            return;
        }

        Schema::table('batch_types', function (Blueprint $table) {
            $table->boolean('is_selectable')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('batch_types', 'is_selectable')) {
            return;
        }

        Schema::table('batch_types', function (Blueprint $table) {
            $table->dropColumn('is_selectable');
        });
    }
};
