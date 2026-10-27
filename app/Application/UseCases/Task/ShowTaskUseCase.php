<?php

declare(strict_types=1);

namespace App\Application\UseCases\Task;

use App\Core\Entities\TaskEntity;
use App\Core\Interfaces\ITaskRepository;

/**
 * One task of the active company, with its providers.
 */
final class ShowTaskUseCase
{
    public function __construct(private readonly ITaskRepository $tasks)
    {
    }

    public function __invoke(int $taskId, int $companyId): ?TaskEntity
    {
        return $this->tasks->findForCompany($taskId, $companyId);
    }
}
