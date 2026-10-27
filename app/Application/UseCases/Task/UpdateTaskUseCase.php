<?php

declare(strict_types=1);

namespace App\Application\UseCases\Task;

use App\Application\DTOs\Task\UpdateTaskDTO;
use App\Core\Entities\TaskEntity;
use App\Core\Interfaces\ITaskRepository;

/**
 * Edits the text, urgency and/or providers of a task of the active company.
 */
final class UpdateTaskUseCase
{
    public function __construct(private readonly ITaskRepository $tasks)
    {
    }

    /** @return TaskEntity|null null when the task does not exist in the company */
    public function __invoke(UpdateTaskDTO $dto): ?TaskEntity
    {
        $task = $this->tasks->findForCompany($dto->taskId, $dto->companyId);
        if ($task === null) {
            return null;
        }

        return $this->tasks->update(
            $task->withChanges($dto->description !== null ? trim($dto->description) : null, $dto->urgency),
            $dto->providerIds !== null ? array_values(array_unique($dto->providerIds)) : null,
        );
    }
}
