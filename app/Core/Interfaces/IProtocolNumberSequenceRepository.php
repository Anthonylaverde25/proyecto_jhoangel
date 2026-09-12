<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

interface IProtocolNumberSequenceRepository
{
    /**
     * Reserve the next number of a series under a pessimistic lock.
     *
     * MUST be called inside an open transaction: the lock is only held until the surrounding
     * transaction commits, and that is exactly what prevents two chutes closing in the same
     * second from minting the same act number.
     */
    public function nextNumber(int $companyId, string $series, int $year): int;
}
