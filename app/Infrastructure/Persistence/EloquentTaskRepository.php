<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\ValueObjects\TaskFilter;
use App\Application\Mappers\TaskMapper;
use App\Core\Entities\TaskEntity;
use App\Core\Enums\TaskUrgency;
use App\Core\Interfaces\ITaskRepository;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EloquentTaskRepository implements ITaskRepository
{
    public function create(TaskEntity $task, array $providerIds): TaskEntity
    {
        return DB::transaction(function () use ($task, $providerIds): TaskEntity {
            $model = Task::query()->create([
                'company_id' => $task->getCompanyId(),
                'description' => $task->getDescription(),
                'urgency' => $task->getUrgency()->value,
            ]);

            $model->providers()->attach(
                array_fill_keys($providerIds, ['company_id' => $task->getCompanyId()])
            );

            return TaskMapper::toDomain($model->load('providers:id,name'));
        });
    }

    public function findForCompany(int $taskId, int $companyId): ?TaskEntity
    {
        $model = Task::query()
            ->with('providers:id,name')
            ->where('tasks.company_id', $companyId)
            ->find($taskId);

        return $model !== null ? TaskMapper::toDomain($model) : null;
    }

    public function update(TaskEntity $task, ?array $providerIds): TaskEntity
    {
        return DB::transaction(function () use ($task, $providerIds): TaskEntity {
            $model = Task::query()
                ->where('tasks.company_id', $task->getCompanyId())
                ->findOrFail($task->getId());

            $model->update([
                'description' => $task->getDescription(),
                'urgency' => $task->getUrgency()->value,
            ]);

            if ($providerIds !== null) {
                $model->providers()->sync(
                    array_fill_keys($providerIds, ['company_id' => $task->getCompanyId()])
                );
                // sync() alone does not bump the task when only its providers change.
                $model->touch();
            }

            return TaskMapper::toDomain($model->load('providers:id,name'));
        });
    }

    public function paginate(TaskFilter $filter): array
    {
        $query = Task::query()
            ->with('providers:id,name')
            ->where('tasks.company_id', $filter->companyId)
            ->when($filter->urgency !== null, fn (Builder $q) => $q->where('urgency', $filter->urgency?->value))
            ->when($filter->providerId !== null, fn (Builder $q) => $q->whereHas(
                'providers',
                fn (Builder $p) => $p->where('providers.id', $filter->providerId)
            ))
            ->when($filter->search !== null && $filter->search !== '', fn (Builder $q) => $q->where(
                'description',
                'like',
                '%' . addcslashes((string) $filter->search, '%_\\') . '%'
            ));

        $total = (clone $query)->count();

        $models = $query
            ->orderByRaw($this->urgencyRankSql() . ' DESC')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($filter->page, $filter->perPage)
            ->get();

        return [
            'items' => $models->map(fn (Task $model): TaskEntity => TaskMapper::toDomain($model))->values()->all(),
            'total' => $total,
            'page' => $filter->page,
            'per_page' => $filter->perPage,
        ];
    }

    /** CASE over the stored codes, ranked by TaskUrgency (portable across MySQL and SQLite). */
    private function urgencyRankSql(): string
    {
        $whens = array_map(
            static fn (TaskUrgency $urgency): string => sprintf("WHEN '%s' THEN %d", $urgency->value, $urgency->rank()),
            TaskUrgency::cases()
        );

        return 'CASE urgency ' . implode(' ', $whens) . ' ELSE 0 END';
    }
}
