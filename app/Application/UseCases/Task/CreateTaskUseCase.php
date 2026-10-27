<?php

declare(strict_types=1);

namespace App\Application\UseCases\Task;

use App\Application\DTOs\Task\CreateTaskDTO;
use App\Core\Entities\TaskEntity;
use App\Core\Interfaces\ITaskRepository;

/**
 * Records a task in the active company, with the providers it concerns. Same path for the web,
 * the assistant and MCP clients.
 */
final class CreateTaskUseCase
{
    public function __construct(private readonly ITaskRepository $tasks)
    {
    }

    public function __invoke(CreateTaskDTO $dto): TaskEntity
    {
        return $this->tasks->create(
            TaskEntity::create($dto->companyId, trim($dto->description), $dto->urgency),
            array_values(array_unique($dto->providerIds)),
        );
    }
}
