<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Provider CUITs become digits only, so `uq_providers_company_cuit` compares the number and not
 * the way it was typed. Formatting is a presentation concern (XX-XXXXXXXX-X, built by the client).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('providers')->select('id', 'company_id', 'cuit')->get();

        // Two rows of one company that differ only in dashes would collide on the unique index.
        $collisions = $rows
            ->groupBy(fn ($row) => $row->company_id . ':' . preg_replace('/\D/', '', (string) $row->cuit))
            ->filter(fn ($group) => $group->count() > 1);

        if ($collisions->isNotEmpty()) {
            throw new RuntimeException('Duplicate provider CUITs after normalization (merge them first): '
                . $collisions->map(fn ($group) => $group->pluck('id')->implode(','))->implode(' | '));
        }

        foreach ($rows as $row) {
            $digits = preg_replace('/\D/', '', (string) $row->cuit);

            if ($digits !== $row->cuit) {
                DB::table('providers')->where('id', $row->id)->update(['cuit' => $digits]);
            }
        }
    }

    public function down(): void
    {
        // Irreversible on purpose: the typed formatting carried no information.
    }
};
