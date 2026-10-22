<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Core\ValueObjects\FiscalLineRead;

/**
 * A line read from the image with the item it was resolved to (Delivery G, part 04 §2). LINKED and
 * MANUAL exist only on the screen: the API answers EXACT, AI or NONE.
 */
final readonly class ResolvedFiscalLine
{
    public const SOURCE_EXACT = 'EXACT';
    public const SOURCE_AI = 'AI';
    public const SOURCE_NONE = 'NONE';

    public const LEVEL_EXACT = 'EXACT';

    public const NOTE_MATCHER_UNAVAILABLE = 'MATCHER_UNAVAILABLE';

    public function __construct(
        public FiscalLineRead $read,
        public string $source,
        /** EXACT, HIGH, MEDIUM or NONE: kept apart from the source to measure the levels. */
        public string $level,
        public ?int $itemId,
        public ?string $reason,
        /** MEDIUM: the candidate the link dialog shows first, not set on the line. */
        public ?int $suggestedItemId,
        public ?int $vatTaxRateId,
        public ?string $note = null,
    ) {
    }
}
