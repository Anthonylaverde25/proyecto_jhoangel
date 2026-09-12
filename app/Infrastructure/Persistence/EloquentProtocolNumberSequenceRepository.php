<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Interfaces\IProtocolNumberSequenceRepository;
use Illuminate\Support\Facades\DB;

class EloquentProtocolNumberSequenceRepository implements IProtocolNumberSequenceRepository
{
    public function nextNumber(int $companyId, string $series, int $year): int
    {
        // The counter row must exist before it can be locked. Creating it outside the lock is
        // safe because the unique index absorbs the race: a concurrent insert loses and we fall
        // through to the lock below either way.
        DB::table('protocol_number_sequences')->insertOrIgnore([
            'company_id' => $companyId,
            'series' => $series,
            'period_year' => $year,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('protocol_number_sequences')
            ->where('company_id', $companyId)
            ->where('series', $series)
            ->where('period_year', $year)
            ->lockForUpdate()
            ->first();

        $next = (int) ($row?->last_number ?? 0) + 1;

        DB::table('protocol_number_sequences')
            ->where('company_id', $companyId)
            ->where('series', $series)
            ->where('period_year', $year)
            ->update(['last_number' => $next, 'updated_at' => now()]);

        return $next;
    }
}
