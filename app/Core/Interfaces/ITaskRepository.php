<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\ValueObjects\TaskFilter;
use App\Core\Entities\TaskEntity;

interface ITaskRepository
{
    /**
     * Stores the task and its providers atomically.
     *
     * @param list<int> $providerIds providers of the task's company
     */
    public function create(TaskEntity $task, array $providerIds): TaskEntity;

    public function findForCompany(int $taskId, int $companyId): ?TaskEntity;

    /**
     * Saves text and urgency; replaces the providers only when a list is given (an empty list removes them).
     *
     * @param list<int>|null $providerIds providers of the task's company, or null to keep them
     */
    public function update(TaskEntity $task, ?array $providerIds): TaskEntity;

    /**
     * Most urgent first, newest first within each level.
     *
     * @return array{items: list<TaskEntity>, total: int, page: int, per_page: int}
     */
    public function paginate(TaskFilter $filter): array;
}
