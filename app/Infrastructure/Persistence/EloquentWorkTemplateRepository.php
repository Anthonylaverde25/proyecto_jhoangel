<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\WorkTemplateEntity;
use App\Core\Interfaces\IWorkTemplateRepository;
use App\Models\WorkTemplate;
use App\Application\Mappers\WorkTemplateMapper;

class EloquentWorkTemplateRepository implements IWorkTemplateRepository
{
    public function findAll(): array
    {
        return WorkTemplate::with('type')->get()
            ->map(fn (WorkTemplate $model) => WorkTemplateMapper::toEntity($model))
            ->toArray();
    }

    public function findByCompanyId(int $companyId): array
    {
        return WorkTemplate::with('type')
            ->where('company_id', $companyId)
            ->get()
            ->map(fn (WorkTemplate $model) => WorkTemplateMapper::toEntity($model))
            ->toArray();
    }

    public function find($id): ?WorkTemplateEntity
    {
        $model = WorkTemplate::with('type')->find($id);
        return $model ? WorkTemplateMapper::toEntity($model) : null;
    }

    public function findById(int $id): ?WorkTemplateEntity
    {
        return $this->find($id);
    }

    public function findBy(array $criteria): array
    {
        $query = WorkTemplate::with('type');
        foreach ($criteria as $field => $value) {
            $query->where($field, $value);
        }
        return $query->get()
            ->map(fn (WorkTemplate $model) => WorkTemplateMapper::toEntity($model))
            ->toArray();
    }

    public function save($entity): void
    {
        $model = $entity->getId() !== null ? WorkTemplate::find($entity->getId()) : null;
        $model = WorkTemplateMapper::toModel($entity, $model);
        $model->save();
    }

    public function delete(int $id): bool
    {
        return (bool) WorkTemplate::destroy($id);
    }
}
