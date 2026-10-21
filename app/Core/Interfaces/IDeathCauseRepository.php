<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

/**
 * The causes of death of the active company.
 */
interface IDeathCauseRepository
{
    /**
     * @return list<array{id: int, code: string, name: string, description: ?string}> the active ones, in catalog order
     */
    public function findActive(): array;

    /**
     * @return array{id: int, code: string, name: string}|null an active cause of the active company
     */
    public function findActiveById(int $id): ?array;
}
