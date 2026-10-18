<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\ValueObjects\ConsumableBatch;

/**
 * Finds a batch of the company to charge a consumption or a service to (DS10). Filters by company
 * explicitly: seeders and commands run without a request context.
 */
interface IConsumableBatchReader
{
    public function find(int $batchId, int $companyId): ?ConsumableBatch;
}
