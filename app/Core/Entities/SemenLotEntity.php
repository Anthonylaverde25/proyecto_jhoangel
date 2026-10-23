<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Exceptions\SemenException;

/**
 * A lot of straws of one semen item, as its centre identifies it (S13). Traceability only: the item
 * keeps the average cost (SL9). The same code of the same item is the same lot (DA3).
 */
final readonly class SemenLotEntity
{
    public function __construct(
        public ?int $id,
        public int $companyId,
        public int $itemId,
        public string $code,
        public ?string $collectionDate = null,
        public ?string $collectionCode = null,
        public ?string $notes = null,
    ) {
    }

    /** DA3: trimmed, single spaces, upper case. */
    public static function normalizeCode(?string $code, string $field = 'lot_code'): string
    {
        $clean = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $code) ?? ''));
        if ($clean === '' || mb_strlen($clean) > 40) {
            throw SemenException::on($field, 'Indicá el número de partida, hasta 40 caracteres.');
        }

        return $clean;
    }
}
