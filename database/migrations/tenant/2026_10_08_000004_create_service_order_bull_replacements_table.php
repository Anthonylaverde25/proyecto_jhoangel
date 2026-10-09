<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('service_order_bull_replacements');

        Schema::create('service_order_bull_replacements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('service_order_id');
            $table->foreignId('retired_male_caravan_id');
            $table->foreignId('replacement_male_caravan_id');
            $table->dateTime('replacement_date');
            $table->string('reason', 50)->comment('LAMENESS_FOOT, PENIS_INJURY, LOW_LIBIDO_RINCONERO, AGGRESSION, DEATH');
            $table->foreignId('destination_batch_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            // Explicit short foreign key names to prevent MySQL 64-char identifier limit
            $table->foreign('service_order_id', 'fk_sobr_order')
                ->references('id')->on('service_orders')->onDelete('cascade');
            $table->foreign('retired_male_caravan_id', 'fk_sobr_retired_male')
                ->references('id')->on('caravans')->onDelete('restrict');
            $table->foreign('replacement_male_caravan_id', 'fk_sobr_repl_male')
                ->references('id')->on('caravans')->onDelete('restrict');
            $table->foreign('destination_batch_id', 'fk_sobr_dest_batch')
                ->references('id')->on('batches')->onDelete('set null');

            $table->index(['company_id', 'service_order_id'], 'idx_sobr_order');
            $table->index(['company_id', 'retired_male_caravan_id'], 'idx_sobr_retired');
            $table->index(['company_id', 'replacement_male_caravan_id'], 'idx_sobr_replacement');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_bull_replacements');
    }
};
