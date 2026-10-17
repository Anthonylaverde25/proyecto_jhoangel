<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Interfaces\IServiceOrderRepository;

/**
 * Single source of service order codes: SO-YYYY-NNN, numbered per company and year.
 *
 * Call it inside the transaction that persists the order: the repository locks the last
 * code of the year, so two concurrent requests cannot read the same sequence.
 */
final class ServiceOrderCodeGenerator
{
    public function __construct(
        private readonly IServiceOrderRepository $repository
    ) {
    }

    public function next(int $companyId, string $plannedStartDate): string
    {
        $year = substr($plannedStartDate, 0, 4);
        if (!ctype_digit($year)) {
            $year = date('Y');
        }

        $prefix = "SO-{$year}-";
        $lastCode = $this->repository->lockLastCodeWithPrefix($companyId, $prefix);

        $sequence = 0;
        if ($lastCode !== null) {
            $parts = explode('-', $lastCode);
            $sequence = (int) end($parts);
        }

        return $prefix . str_pad((string) ($sequence + 1), 3, '0', STR_PAD_LEFT);
    }
}
