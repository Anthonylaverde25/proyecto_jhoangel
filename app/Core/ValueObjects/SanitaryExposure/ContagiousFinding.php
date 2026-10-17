<?php

declare(strict_types=1);

namespace App\Core\ValueObjects\SanitaryExposure;

use DateTimeImmutable;

/**
 * A contagious, disqualifying pathogen found in a bull, and the period in which he may have
 * been carrying it: from his last negative result (or a validity window back) to the finding.
 */
final readonly class ContagiousFinding
{
    public function __construct(
        public string $pathogenCode,
        public ?string $pathogenName,
        public DateTimeImmutable $detectedOn,
        public DateTimeImmutable $riskWindowFrom,
        public bool $riskWindowFromLastNegative
    ) {
    }
}
