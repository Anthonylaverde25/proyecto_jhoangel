<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\ValueObjects\ExitedAnimalCriteria;

/**
 * Read model of the animals that left the company: one flat row per exited line of any exit order,
 * for sales analysis (by category, buyer, activity or period). Pending and skipped lines are not
 * included: what was agreed but did not leave is read from the order itself.
 */
interface IExitedAnimalReader
{
    /** @return list<array<string, mixed>> */
    public function exited(ExitedAnimalCriteria $criteria): array;
}
