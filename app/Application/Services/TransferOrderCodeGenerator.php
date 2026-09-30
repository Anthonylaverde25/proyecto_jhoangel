<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Interfaces\ITransferOrderRepository;

/**
 * TR-YYYYMMDD-NNNN, sequential per company and day.
 *
 * After the prefix only digits and dashes: no letter that can be read as a number. That is the
 * lesson of "GRO001" scanned as "GR0001" in three sheets out of five.
 *
 * Must be called inside the transaction that inserts the order: the last code is read with a
 * lock, and the unique index is the backstop if two issues still race.
 */
final class TransferOrderCodeGenerator
{
    public const PATTERN = '/TR-\d{8}-\d{4}/';

    public function __construct(private readonly ITransferOrderRepository $repository)
    {
    }

    public function next(int $companyId, \DateTimeInterface $day): string
    {
        $prefix = 'TR-' . $day->format('Ymd') . '-';
        $last = $this->repository->lastCodeWithPrefix($prefix, $companyId);
        $sequence = $last !== null ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
