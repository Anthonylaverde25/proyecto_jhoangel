<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\ItemDocumentType;

/**
 * The other half of a transfer (DD7), as read: the entry of an exit, or the exit of an entry.
 */
final readonly class TransferCounterpart
{
    public function __construct(
        public int $id,
        public int $number,
        public ItemDocumentType $type,
        public int $warehouseId,
        public string $warehouseCode,
        public string $warehouseName,
    ) {
    }
}
