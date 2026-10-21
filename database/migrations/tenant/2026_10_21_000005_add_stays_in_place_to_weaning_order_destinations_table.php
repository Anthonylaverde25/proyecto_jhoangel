<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A weaning may leave the calves where they are: no batch to name, nothing to create.
        Schema::table('weaning_order_destinations', function (Blueprint $table) {
            $table->boolean('stays_in_place')->default(false)->after('is_confined');
        });
    }

    public function down(): void
    {
        Schema::table('weaning_order_destinations', function (Blueprint $table) {
            $table->dropColumn('stays_in_place');
        });
    }
};
