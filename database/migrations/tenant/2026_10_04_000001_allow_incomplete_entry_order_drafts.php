<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A draft only needs to know who sells and from where: the rest of the troop can be completed
 * later. Everything else is demanded when the purchase is confirmed, so these columns are only
 * null on a draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entry_orders', function (Blueprint $table) {
            $table->string('batch_name', 255)->nullable()->change();
            $table->unsignedInteger('head_count')->nullable()->change();
            $table->unsignedBigInteger('category_id')->nullable()->change();
            $table->string('sex_composition', 8)->nullable()->change();
            $table->string('condition', 16)->nullable()->change();
            $table->boolean('knows_to_eat')->nullable()->change();
            $table->boolean('tick_vaccinated')->nullable()->change();
            $table->decimal('estimated_weight', 8, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('entry_orders', function (Blueprint $table) {
            $table->string('batch_name', 255)->nullable(false)->change();
            $table->unsignedInteger('head_count')->nullable(false)->change();
            $table->unsignedBigInteger('category_id')->nullable(false)->change();
            $table->string('sex_composition', 8)->nullable(false)->change();
            $table->string('condition', 16)->nullable(false)->change();
            $table->boolean('knows_to_eat')->nullable(false)->change();
            $table->boolean('tick_vaccinated')->nullable(false)->change();
            $table->decimal('estimated_weight', 8, 2)->nullable(false)->change();
        });
    }
};
