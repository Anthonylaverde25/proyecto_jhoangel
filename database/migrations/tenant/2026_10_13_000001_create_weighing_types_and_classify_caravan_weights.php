<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A weight without its purpose cannot be read: 190 kg at weaning and 190 kg off a truck are
     * different facts. This adds the catalog of purposes and the columns that describe how each
     * weight was taken.
     */
    public function up(): void
    {
        Schema::create('weighing_types', function (Blueprint $table) {
            $table->id();
            // BIRTH | WEANING | CONTROL | BATCH_CONTROL | PRE_SERVICE | PREGNANCY_CHECK | ENTRY | EXIT | EMERGENCY
            $table->string('code', 32)->unique();
            $table->string('name', 64);
            $table->text('description')->nullable();

            // Informative copy of WeighingTypeCode::affectsAdpv(); the enum is the rule.
            $table->boolean('affects_adpv')->default(true);

            // INDIVIDUAL | AVERAGE | PORTABLE_FIELD | ESTIMATED
            $table->string('default_method', 16)->default('INDIVIDUAL');
            // FULL_WEIGHT | SHRUNK_12H | CALCULATED
            $table->string('default_shrunk_condition', 16)->default('FULL_WEIGHT');

            $table->unsignedSmallInteger('display_order')->default(1);
            $table->boolean('is_system')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'display_order']);
        });

        Schema::table('caravan_weights', function (Blueprint $table) {
            $table->foreignId('weighing_type_id')->nullable()->after('caravan_id')
                ->constrained('weighing_types')->restrictOnDelete();

            // PORTABLE_FIELD does not fit in the original 12 characters.
            $table->string('method', 16)->nullable()->change();

            // FULL_WEIGHT | SHRUNK_12H | CALCULATED. The measured weight is never altered.
            $table->string('shrunk_condition', 16)->default('FULL_WEIGHT')->after('method');
            // Only for CALCULATED: the percentage to discount when normalising, e.g. 6.00.
            $table->decimal('shrink_pct', 4, 2)->nullable()->after('shrunk_condition');
            $table->time('weighing_time')->nullable()->after('weighing_date');

            // MANUAL | WEIGHING_ORDER | WEANING | ACTIVITY_CHANGE | ENTRY | BIRTH | IMPORT
            $table->string('source', 24)->nullable()->after('shrink_pct');

            $table->index(['caravan_id', 'weighing_date']);
        });

        Schema::table('batch_weights', function (Blueprint $table) {
            // Only set on CONTROL points produced by a weighing. Movement points carry none.
            $table->foreignId('weighing_type_id')->nullable()->after('activity_id')
                ->constrained('weighing_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('batch_weights', function (Blueprint $table) {
            $table->dropConstrainedForeignId('weighing_type_id');
        });

        Schema::table('caravan_weights', function (Blueprint $table) {
            $table->dropIndex(['caravan_id', 'weighing_date']);
            $table->dropConstrainedForeignId('weighing_type_id');
            $table->dropColumn(['shrunk_condition', 'shrink_pct', 'weighing_time', 'source']);
            $table->string('method', 12)->nullable()->change();
        });

        Schema::dropIfExists('weighing_types');
    }
};
