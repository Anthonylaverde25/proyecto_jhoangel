<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A lot of straws of one semen item, as the centre identifies it (S13). Traceability only: the item
 * keeps its average cost (SL9). Same code of the same item = same lot (DA3: code stored normalised).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semen_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->string('code', 40);                               // normalised, upper case (DA3)
            $table->date('collection_date')->nullable();
            $table->string('collection_code', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['id', 'company_id'], 'uq_slot_id_company');
            $table->unique(['id', 'item_id'], 'uq_slot_id_item');                 // target of the line FK (SL2)
            $table->unique(['company_id', 'item_id', 'code'], 'uq_slot_company_item_code');
            $table->foreign(['item_id', 'company_id'])               // semen_lots_item_id_company_id_foreign
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE semen_lots ADD CONSTRAINT chk_slot_code CHECK (CHAR_LENGTH(TRIM(code)) > 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('semen_lots');
    }
};
