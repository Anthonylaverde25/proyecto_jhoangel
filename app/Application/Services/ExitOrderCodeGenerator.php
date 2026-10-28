<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Interfaces\IExitOrderRepository;

/**
 * The code of an exit order, EG-YYYYMMDD-NNNN, sequential per company and day. "EG" has neither an
 * O nor an I, so a scan's O→0 / I→1 correction cannot touch it. Must be called inside the
 * transaction that inserts the order: the last code is read with a lock.
 */
final class ExitOrderCodeGenerator
{
    public const PREFIX = 'EG';

    public function __construct(private readonly IExitOrderRepository $repository)
    {
    }

    public function nextCode(int $companyId, \DateTimeInterface $day): string
    {
        $prefix = self::PREFIX . '-' . $day->format('Ymd') . '-';
        $last = $this->repository->lastCodeWithPrefix($prefix, $companyId);
        $sequence = $last !== null ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
