<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration I — the catalogue must not fragment over typing differences.
 *
 * `health_centers` had no uniqueness rule at all, unlike `veterinarians`. Once laboratories can
 * be created on the fly from the chute sheet, "Lab Rosario", "Laboratorio Rosario" and
 * "LABORATORIO ROSARIO" become three different destinations and the traceability of derivations
 * fragments in silence.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->retireDuplicates();

        Schema::table('health_centers', function (Blueprint $table): void {
            // Generated column: the uniqueness rule lives in the schema, not in the callers.
            $table->string('name_normalized', 150)->virtualAs('LOWER(TRIM(name))');
            $table->unique(['company_id', 'name_normalized'], 'hc_company_name_unq');
        });
    }

    public function down(): void
    {
        Schema::table('health_centers', function (Blueprint $table): void {
            $table->dropUnique('hc_company_name_unq');
            $table->dropColumn('name_normalized');
        });
    }

    /**
     * Rows are never deleted: a health centre is referenced by signed documents. The oldest row
     * of each collision survives and keeps its name, everything still pointing at a duplicate is
     * repointed at that survivor, and the duplicate is renamed and deactivated so the office can
     * still see what happened instead of finding evidence silently gone.
     */
    private function retireDuplicates(): void
    {
        $groups = DB::table('health_centers')
            ->select('company_id', DB::raw('LOWER(TRIM(name)) as normalized'), DB::raw('COUNT(*) as total'))
            ->groupBy('company_id', 'normalized')
            ->having('total', '>', 1)
            ->get();

        foreach ($groups as $group) {
            $rows = DB::table('health_centers')
                ->where('company_id', $group->company_id)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$group->normalized])
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $survivorId = (int) array_shift($rows);

            foreach ($rows as $duplicateId) {
                $duplicateId = (int) $duplicateId;

                DB::table('veterinarians')->where('health_center_id', $duplicateId)
                    ->update(['health_center_id' => $survivorId]);
                DB::table('diagnostic_protocols')->where('health_center_id', $duplicateId)
                    ->update(['health_center_id' => $survivorId]);
                DB::table('diagnostic_protocols')->where('derived_to_health_center_id', $duplicateId)
                    ->update(['derived_to_health_center_id' => $survivorId]);
                DB::table('sample_receptions')->where('health_center_id', $duplicateId)
                    ->update(['health_center_id' => $survivorId]);

                DB::table('health_centers')->where('id', $duplicateId)->update([
                    'name' => DB::raw("CONCAT(LEFT(name, 120), ' (duplicado #{$duplicateId})')"),
                    'is_active' => false,
                    'notes' => DB::raw(sprintf(
                        "CONCAT(COALESCE(notes, ''), '\nFusionado con el centro de salud #%d por unicidad de catálogo.')",
                        $survivorId
                    )),
                ]);
            }
        }
    }
};
