<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Core\Entities\EntryOrderEntity;
use App\Core\Interfaces\IEntryOrderRepository;

/**
 * Finds an order by the code printed on its sheet. A code read off paper is cleaned first: spaces
 * dropped, and an O or an I/L read where a digit must be turned into 0 or 1. The prefix "EN" has
 * neither letter, so the correction never touches it.
 */
final class FindEntryOrderByCodeUseCase
{
    public function __construct(private readonly IEntryOrderRepository $repository)
    {
    }

    public function __invoke(string $code, int $companyId): ?EntryOrderEntity
    {
        $clean = strtoupper((string) preg_replace('/\s+/', '', $code));

        if (preg_match('/^EN-?([0-9OIL]{8})-?([0-9OIL]{1,4})$/', $clean, $m) === 1) {
            $digits = fn (string $s) => strtr($s, ['O' => '0', 'I' => '1', 'L' => '1']);
            $clean = 'EN-' . $digits($m[1]) . '-' . str_pad($digits($m[2]), 4, '0', STR_PAD_LEFT);
        }

        return $this->repository->findByCode($clean, $companyId);
    }
}
