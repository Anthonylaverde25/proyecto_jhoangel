<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Interfaces\IItemUsageReader;
use Illuminate\Support\Facades\DB;

class EloquentItemUsageReader implements IItemUsageReader
{
    public function itemHasLines(int $itemId, int $companyId): bool
    {
        return DB::table('fiscal_document_lines')->where('company_id', $companyId)->where('item_id', $itemId)->exists()
            || DB::table('item_document_lines')->where('company_id', $companyId)->where('item_id', $itemId)->exists();
    }

    public function presentationHasLines(int $presentationId): bool
    {
        return DB::table('fiscal_document_lines')->where('input_presentation_id', $presentationId)->exists()
            || DB::table('item_document_lines')->where('input_presentation_id', $presentationId)->exists();
    }

    public function unitInputLines(int $unitId, int $companyId): int
    {
        return DB::table('fiscal_document_lines')->where('company_id', $companyId)->where('input_unit_id', $unitId)->count()
            + DB::table('item_document_lines')->where('company_id', $companyId)->where('input_unit_id', $unitId)->count();
    }

    public function nonZeroBalance(int $itemId, int $companyId): ?string
    {
        $quantities = DB::table('item_stocks')
            ->where('company_id', $companyId)
            ->where('item_id', $itemId)
            ->pluck('quantity');

        foreach ($quantities as $quantity) {
            if (bccomp((string) $quantity, '0', 4) !== 0) {
                return (string) $quantity;
            }
        }

        return null;
    }
}
