<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entry orders: the purchase of an external troop, declared before its animals exist in the
 * system. The order is born without caravans; they arrive later with each official transit
 * document (DTE), so an order may wait in AWAITING_DTE for days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');

            // EN-YYYYMMDD-NNNN, issued by the server: what gets printed and what a scan resolves.
            $table->string('code', 32);

            // Company-wide correlative (1, 2, 3...). Feeds the suggested batch name "{auction}-{number}".
            $table->unsignedInteger('number');

            // DRAFT | AWAITING_DTE | PARTIAL | COMPLETED | CLOSED_INCOMPLETE | CANCELLED
            $table->string('status', 24);

            // PLANNED (purchase confirmed, DTE pending) | REGISTERED (created together with its DTE)
            $table->string('kind', 16)->default('PLANNED');

            // Origin: the seller and the establishment the animals come from.
            $table->foreignId('provider_id')->constrained('providers')->onDelete('restrict');
            $table->foreignId('farm_id')->constrained('farms')->onDelete('restrict');

            // Auction lot termination (e.g. "338"). Optional: not every purchase is an auction.
            $table->string('auction_number', 20)->nullable();

            // The external batch the animals land in. Null while DRAFT: it is created on confirmation.
            $table->foreignId('batch_id')->nullable()->constrained('batches')->onDelete('restrict');

            // Name chosen for the batch and how: AUTO ("{auction}-{number}") | CUSTOM.
            $table->string('batch_name', 255);
            $table->string('batch_name_mode', 8)->default('CUSTOM');

            // Classification of the batch to create, kept so a draft can be confirmed later. No
            // management system: an external batch only holds the purchase until its animals are
            // assigned to an own batch, and that one declares how it is fed.
            $table->foreignId('activity_id')->constrained('activities')->onDelete('restrict');
            $table->foreignId('batch_type_id')->constrained('batch_types')->onDelete('restrict');

            // Troop as purchased. Sex is declared here, once, for the whole order.
            $table->unsignedInteger('head_count');
            $table->foreignId('category_id')->constrained('animal_categories')->onDelete('restrict');
            $table->string('sex_composition', 8);                 // MALE | FEMALE | MIXED
            $table->unsignedInteger('male_count')->nullable();    // only when MIXED; male + female = head_count
            $table->unsignedInteger('female_count')->nullable();
            $table->string('condition', 16);                      // REGULAR | GOOD | VERY_GOOD | EXCELLENT
            $table->unsignedTinyInteger('age_min_months')->nullable();
            $table->unsignedTinyInteger('age_max_months')->nullable();
            $table->boolean('knows_to_eat');
            $table->boolean('tick_vaccinated');
            $table->decimal('shrink_percent', 5, 2)->nullable();
            $table->decimal('estimated_weight', 8, 2);
            $table->decimal('min_weight', 8, 2)->nullable();
            $table->decimal('max_weight', 8, 2)->nullable();

            // Day the purchase was closed (auction day). Arrival dates live on each DTE.
            $table->date('purchase_date');

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('first_dte_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->string('responsable')->nullable();
            $table->text('observations')->nullable();
            $table->text('closing_reason')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'provider_id']);
        });

        Schema::create('entry_order_breeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('entry_order_id')->constrained('entry_orders')->onDelete('cascade');
            $table->foreignId('breed_id')->constrained('breeds')->onDelete('restrict');

            // Coat colour; must be one breed_color allows for the breed. Null when not declared.
            $table->foreignId('color_id')->nullable()->constrained('colors')->onDelete('restrict');

            // 1-based; printed as the letter A, B, C...
            $table->unsignedTinyInteger('position');

            $table->timestamps();

            $table->unique(['entry_order_id', 'position']);
            $table->unique(['entry_order_id', 'breed_id', 'color_id']);
        });

        // One row per official transit document received for the order.
        Schema::create('entry_order_dtes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('entry_order_id')->constrained('entry_orders')->onDelete('cascade');
            $table->string('dte_number', 40);
            $table->date('dte_date');      // issue date printed on the DTE
            $table->date('entered_at');    // day the animals entered
            $table->unsignedInteger('head_count');
            $table->foreignId('loaded_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('observations')->nullable();
            $table->timestamps();

            // The same DTE cannot be loaded twice in the company, in this order or any other.
            $table->unique(['company_id', 'dte_number']);
            $table->index('entry_order_id');
        });

        Schema::create('entry_order_animals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('entry_order_id')->constrained('entry_orders')->onDelete('cascade');
            $table->foreignId('entry_order_dte_id')->constrained('entry_order_dtes')->onDelete('restrict');
            $table->foreignId('caravan_id')->constrained('caravans')->onDelete('restrict');

            // Breed row the animal was declared with; null when left blank.
            $table->foreignId('entry_order_breed_id')->nullable()->constrained('entry_order_breeds')->onDelete('set null');
            $table->foreignId('caravan_movement_id')->nullable()->constrained('caravan_movements')->onDelete('set null');

            $table->timestamps();

            $table->unique(['entry_order_id', 'caravan_id']);
        });

        // Same shape as the transfer and weaning order histories.
        Schema::create('entry_order_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('entry_order_id')->constrained('entry_orders')->onDelete('cascade');

            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('action_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('action_reason')->nullable();

            // Which DTE was loaded, how many head it brought, how many are left.
            $table->json('action_metadata')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'entry_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_order_histories');
        Schema::dropIfExists('entry_order_animals');
        Schema::dropIfExists('entry_order_dtes');
        Schema::dropIfExists('entry_order_breeds');
        Schema::dropIfExists('entry_orders');
    }
};
