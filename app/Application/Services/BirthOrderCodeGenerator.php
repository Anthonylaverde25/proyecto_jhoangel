<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Interfaces\IBirthOrderRepository;

/**
 * PA-YYYYMMDD-NNNN, sequential per company and day.
 *
 * A prefix of its own, not "PAR": the sheet already prints PAR-01 as its template code, and two
 * codes that start alike on the same paper are one misreading apart. After the prefix only digits
 * and dashes, so an O read where a digit must be can be corrected without guessing.
 *
 * Must be called inside the transaction that inserts the order: the last code is read with a lock,
 * and the unique index is the backstop if two issues still race.
 */
final class BirthOrderCodeGenerator
{
    public const PREFIX = 'PA';

    public function __construct(private readonly IBirthOrderRepository $repository)
    {
    }

    public function next(int $companyId, \DateTimeInterface $day): string
    {
        $prefix = self::PREFIX . '-' . $day->format('Ymd') . '-';
        $last = $this->repository->lastCodeWithPrefix($prefix, $companyId);
        $sequence = $last !== null ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
