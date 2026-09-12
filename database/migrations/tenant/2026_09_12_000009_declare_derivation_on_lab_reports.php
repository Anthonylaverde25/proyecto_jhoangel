<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-31 (rev.): the derivation is DECLARED by the professional, and the report carries TWO
 * institutions instead of one.
 *
 * The first version inferred "it was derived" from "the analysing CUIT differs from the
 * professional's". That inference is wrong. A veterinarian employed at a laboratory has a CUIT
 * different from their employer's and nothing was derived, so the comparison produced false
 * positives and demanded somebody else's PDF for a report the professional had signed from
 * their own bench. Whether the samples left the building is a fact only the acting professional
 * holds, and there is no datum on file from which it follows.
 *
 * The two columns keep one stable meaning each, whatever the professional ticks:
 *
 *   reporting_institution  the centre the professional reports from — where the tubes were
 *                          received and stored. Present on EVERY report: a protocol names its
 *                          institution and CUIT even when the work never left the premises.
 *   analysing_institution  the third party that ran the assay. Present only on a derivation.
 *
 * So "who analysed this" reads `analysing_institution ?? reporting_institution`, and the
 * existing rows convert cleanly: every one of them predates the flag, none was declared a
 * derivation, and what their single block described was the professional's own centre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diagnostic_protocols', function (Blueprint $table): void {
            $table->boolean('is_derived')->default(false)->after('analysing_institution');
            $table->json('reporting_institution')->nullable()->after('analysing_institution');

            $table->index(['company_id', 'is_derived'], 'dp_company_derived_idx');
        });

        // What the single block held was the institution the professional reported from.
        DB::table('diagnostic_protocols')
            ->whereNotNull('analysing_institution')
            ->update([
                'reporting_institution' => DB::raw('analysing_institution'),
                'analysing_institution' => null,
            ]);
    }

    public function down(): void
    {
        DB::table('diagnostic_protocols')
            ->whereNull('analysing_institution')
            ->whereNotNull('reporting_institution')
            ->update(['analysing_institution' => DB::raw('reporting_institution')]);

        Schema::table('diagnostic_protocols', function (Blueprint $table): void {
            $table->dropIndex('dp_company_derived_idx');
            $table->dropColumn(['is_derived', 'reporting_institution']);
        });
    }
};
