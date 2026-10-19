<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Exceptions\WarehouseException;

/**
 * A warehouse's short code (DD1): trimmed, upper case, letters, digits and dashes, up to 32.
 */
final readonly class WarehouseCode
{
    private const PREFIX = 'ALM-';

    public string $value;

    public function __construct(string $raw)
    {
        $value = mb_strtoupper(trim($raw));
        if (preg_match('/^[A-Z0-9-]{1,32}$/', $value) !== 1) {
            throw WarehouseException::on('code', 'Usá letras, números y guiones (hasta 32).');
        }
        $this->value = $value;
    }

    /**
     * The next free ALM-NN after the highest one in use, at least two digits.
     *
     * @param list<string> $taken
     */
    public static function next(array $taken): string
    {
        $highest = 0;
        foreach ($taken as $code) {
            if (preg_match('/^' . self::PREFIX . '(\d+)$/', mb_strtoupper($code), $m) === 1) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return self::PREFIX . str_pad((string) ($highest + 1), 2, '0', STR_PAD_LEFT);
    }
}
