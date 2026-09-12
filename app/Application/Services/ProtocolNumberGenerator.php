<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Enums\DiagnosticProtocolType;
use App\Core\Interfaces\IProtocolNumberSequenceRepository;
use Illuminate\Support\Carbon;

/**
 * ADR-12: an extraction act carries a number minted by the system, never one typed by an
 * operator. The number printed on the laboratory report does not exist yet when the tubes are
 * drawn, and making the producer invent it amounts to forging a third party's document id.
 */
final class ProtocolNumberGenerator
{
    public function __construct(
        private readonly IProtocolNumberSequenceRepository $sequences
    ) {
    }

    /**
     * Format: ACTA-2026-0007. Must run inside the transaction that persists the act.
     */
    public function nextExtractionActNumber(int $companyId, ?string $referenceDate = null): string
    {
        $year = (int) Carbon::parse($referenceDate ?? Carbon::now()->toDateString())->format('Y');

        $series = DiagnosticProtocolType::EXTRACTION_ACT->numberSeries() ?? 'ACT';
        $next = $this->sequences->nextNumber($companyId, $series, $year);

        return sprintf('ACTA-%d-%04d', $year, $next);
    }
}
