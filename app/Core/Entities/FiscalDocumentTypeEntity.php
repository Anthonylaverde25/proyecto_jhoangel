<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\FiscalDocumentKind;

/**
 * A row of the fixed catalog of provider document types (DS15). The code reads behaviour from it,
 * never from the name.
 */
final readonly class FiscalDocumentTypeEntity
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public ?string $afipCode,
        public FiscalDocumentKind $kind,
        public ?string $letter,
        public bool $discriminatesVat,
        public bool $carriesAmounts,
        public int $sign,
        public bool $isActive,
    ) {
    }
}
