<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Interfaces\IWeighingOrderRepository;

/**
 * PS-YYYYMMDD-NNNN, sequential per company and day.
 *
 * Not "PES": the sheets print PES-01 / PES-02 as their template code, and two codes that start
 * alike on the same paper are one misreading apart. Must be called inside the transaction that
 * inserts the order; the unique index is the backstop if two registrations still race.
 */
final class WeighingOrderCodeGenerator
{
    public const PREFIX = 'PS';

    public function __construct(private readonly IWeighingOrderRepository $repository)
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
