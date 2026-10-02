<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Interfaces\IEntryOrderRepository;

/**
 * The two identifiers of an entry order:
 *
 * - its code, EN-YYYYMMDD-NNNN, sequential per company and day: what the sheet prints and a scan
 *   resolves. "EN" has neither an O nor an I, so the scan's O→0 / I→1 correction cannot touch it,
 *   and it does not look like ING-02, printed on the same sheet;
 * - its number, a company-wide correlative that never restarts: what the suggested batch name
 *   "{auction}-{number}" uses.
 *
 * Must be called inside the transaction that inserts the order: both are read with a lock, and
 * the unique indexes are the backstop if two creations still race.
 */
final class EntryOrderCodeGenerator
{
    public const PREFIX = 'EN';

    public function __construct(private readonly IEntryOrderRepository $repository)
    {
    }

    public function nextCode(int $companyId, \DateTimeInterface $day): string
    {
        $prefix = self::PREFIX . '-' . $day->format('Ymd') . '-';
        $last = $this->repository->lastCodeWithPrefix($prefix, $companyId);
        $sequence = $last !== null ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function nextNumber(int $companyId): int
    {
        return $this->repository->lastNumber($companyId) + 1;
    }
}
