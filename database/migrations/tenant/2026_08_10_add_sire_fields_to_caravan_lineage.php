<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds sire traceability fields to caravan_lineage.
     * - sire_assigned_at: timestamp when the father was assigned post-birth.
     * - sire_identification_method: how paternity was determined (operational/phenotype/lab_genetic).
     * - sire_notes: free-text field for evidence description (e.g., "White-faced calf - Hereford confirmed").
     */
    public function up(): void
    {
        Schema::table('caravan_lineage', function (Blueprint $table) {
            $table->timestamp('sire_assigned_at')->nullable()->after('is_nursing');
            $table->string('sire_identification_method', 20)->nullable()->after('sire_assigned_at');
            $table->text('sire_notes')->nullable()->after('sire_identification_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('caravan_lineage', function (Blueprint $table) {
            $table->dropColumn(['sire_assigned_at', 'sire_identification_method', 'sire_notes']);
        });
    }
};
