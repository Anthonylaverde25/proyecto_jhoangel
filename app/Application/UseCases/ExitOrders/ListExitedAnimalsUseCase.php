<?php

declare(strict_types=1);

namespace App\Application\UseCases\ExitOrders;

use App\Core\Interfaces\IExitedAnimalReader;
use App\Core\ValueObjects\ExitedAnimalCriteria;

/**
 * The animals that left, one row each, across exit orders: the base the assistant and the reports
 * group by category, buyer, activity or period (kg moved, $/kg, amounts).
 */
final class ListExitedAnimalsUseCase
{
    public function __construct(private readonly IExitedAnimalReader $reader)
    {
    }

    /** @return list<array<string, mixed>> */
    public function __invoke(ExitedAnimalCriteria $criteria): array
    {
        return $this->reader->exited($criteria);
    }
}
