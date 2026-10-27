<?php

declare(strict_types=1);

namespace App\Application\UseCases\Task;

use App\Core\ValueObjects\TaskFilter;
use App\Core\Entities\TaskEntity;
use App\Core\Interfaces\ITaskRepository;

/**
 * Tasks of the active company, most urgent first.
 */
final class ListTasksUseCase
{
    public function __construct(private readonly ITaskRepository $tasks)
    {
    }

    /** @return array{items: list<TaskEntity>, total: int, page: int, per_page: int} */
    public function __invoke(TaskFilter $filter): array
    {
        return $this->tasks->paginate($filter);
    }
}
