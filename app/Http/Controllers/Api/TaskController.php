<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Task\CreateTaskDTO;
use App\Application\DTOs\Task\UpdateTaskDTO;
use App\Application\UseCases\Task\CreateTaskUseCase;
use App\Application\UseCases\Task\ListTasksUseCase;
use App\Application\UseCases\Task\ShowTaskUseCase;
use App\Application\UseCases\Task\UpdateTaskUseCase;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Task\ListTasksRequest;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use Illuminate\Http\JsonResponse;

/**
 * Company tasks. Creating and editing them are the only writes an MCP token may perform (RestrictMcpTokenScope).
 */
class TaskController extends Controller
{
    public function index(ListTasksRequest $request, ListTasksUseCase $useCase, ICompanyContext $companyContext): JsonResponse
    {
        $page = $useCase($request->toFilter((int) $companyContext->getCompanyId()));

        return response()->json([
            'data' => TaskResource::collection($page['items'])->resolve($request),
            'meta' => [
                'total' => $page['total'],
                'page' => $page['page'],
                'per_page' => $page['per_page'],
                'last_page' => max(1, (int) ceil($page['total'] / $page['per_page'])),
            ],
        ]);
    }

    public function store(StoreTaskRequest $request, CreateTaskUseCase $useCase, ICompanyContext $companyContext): JsonResponse
    {
        $task = $useCase(new CreateTaskDTO(
            companyId: (int) $companyContext->getCompanyId(),
            description: (string) $request->validated('description'),
            urgency: $request->urgency(),
            providerIds: $request->providerIds(),
        ));

        return (new TaskResource($task))->response()->setStatusCode(201);
    }

    public function show(int $id, ShowTaskUseCase $useCase, ICompanyContext $companyContext): JsonResponse
    {
        $task = $useCase($id, (int) $companyContext->getCompanyId());

        return $task !== null
            ? (new TaskResource($task))->response()
            : response()->json(['message' => 'La tarea no existe.'], 404);
    }

    public function update(int $id, UpdateTaskRequest $request, UpdateTaskUseCase $useCase, ICompanyContext $companyContext): JsonResponse
    {
        $task = $useCase(new UpdateTaskDTO(
            taskId: $id,
            companyId: (int) $companyContext->getCompanyId(),
            description: $request->description(),
            urgency: $request->urgency(),
            providerIds: $request->providerIds(),
        ));

        return $task !== null
            ? (new TaskResource($task))->response()
            : response()->json(['message' => 'La tarea no existe.'], 404);
    }
}
