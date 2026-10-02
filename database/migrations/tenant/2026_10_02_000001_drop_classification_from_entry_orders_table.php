<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An entry order no longer classifies its batch. The external batch only holds the purchase: its
 * animals are not in the productive flow until they are assigned to an own batch, and that one
 * declares its activity and type when it is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entry_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activity_id');
            $table->dropConstrainedForeignId('batch_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('entry_orders', function (Blueprint $table) {
            $table->foreignId('activity_id')->nullable()->after('batch_name_mode')->constrained('activities')->onDelete('restrict');
            $table->foreignId('batch_type_id')->nullable()->after('activity_id')->constrained('batch_types')->onDelete('restrict');
        });
    }
};
