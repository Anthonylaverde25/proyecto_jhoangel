<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class ImportCaravansDTO
{
    /**
     * @param array<int, array<string, string>> $rows Mapped rows from OCR (field names already resolved)
     * @param string $targetModel Target model name for context
     */
    public function __construct(
        public array $rows,
        public string $targetModel = 'caravans',
        public string $workType = 'entry',
        public ?int $batchId = null,
        public ?int $farmId = null,
        public ?string $batchName = null,
    ) {
    }
}
