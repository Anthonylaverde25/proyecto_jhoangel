<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the chute saw on an animal as it came off the truck: an injured eye, a damaged ear or a
 * limb problem (APLOMO — lameness or a knock on the legs), most likely from the trip. Each row is
 * a box marked on its reception line, by hand or on an ING-03; an unmarked box is "not seen", not
 * "sound". It belongs to the reception (`entry_order_animals`), so correcting the reception takes
 * it along; `caravan_id` is kept to query an animal's history directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_order_arrival_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('entry_order_animal_id')->constrained('entry_order_animals')->onDelete('cascade');
            $table->foreignId('caravan_id')->constrained('caravans')->onDelete('cascade');
            $table->foreignId('entry_order_dte_id')->constrained('entry_order_dtes')->onDelete('cascade');

            // EYE | EAR | LIMB
            $table->string('finding', 8);
            $table->date('observed_at');

            // The ING-03 sheet that recorded it; null when it was entered by hand.
            $table->foreignId('entry_order_receipt_sheet_id')->nullable()->constrained('entry_order_receipt_sheets', 'id', 'eo_arrival_findings_sheet_foreign')->nullOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['entry_order_animal_id', 'finding'], 'eo_arrival_findings_animal_finding_unique');
            $table->index(['entry_order_dte_id', 'finding'], 'eo_arrival_findings_dte_finding_index');
            $table->index('caravan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_order_arrival_findings');
    }
};
