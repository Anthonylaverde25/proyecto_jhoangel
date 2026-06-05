<?php

declare(strict_types=1);

namespace App\Application\DTOs;

/**
 * DTO for importing gestation diagnoses from OCR-processed data.
 *
 * Each row contains identification (string) instead of caravan_id (int)
 * because the OCR extracts the tag number, not the database ID.
 */
final readonly class ImportOCRGestationDiagnosisDTO
{
    /**
     * @param array<int, array{identification: string, diagnostico: string, gestation_stage?: string|null, observations?: string|null}> $rows
     * @param int $serviceOrderId Required for traceability
     * @param string|null $diagnosisDate Defaults to today if not provided
     */
    public function __construct(
        public array  $rows,
        public int    $serviceOrderId,
        public ?string $diagnosisDate = null,
    ) {
    }
}
