<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\TemplateTypeEntity;
use App\Core\Interfaces\ITemplateTypeRepository;
use App\Models\TemplateType;
use App\Application\Mappers\TemplateTypeMapper;

class EloquentTemplateTypeRepository implements ITemplateTypeRepository
{
    public function findAll(): array
    {
        return TemplateType::all()
            ->map(fn (TemplateType $model) => TemplateTypeMapper::toEntity($model))
            ->toArray();
    }

    public function findByCompanyId(int $companyId): array
    {
        return TemplateType::where('company_id', $companyId)
            ->where('is_active', true)
            ->get()
            ->map(fn (TemplateType $model) => TemplateTypeMapper::toEntity($model))
            ->toArray();
    }

    public function find($id): ?TemplateTypeEntity
    {
        $model = TemplateType::find($id);
        return $model ? TemplateTypeMapper::toEntity($model) : null;
    }

    public function findById(int $id): ?TemplateTypeEntity
    {
        return $this->find($id);
    }

    public function findBy(array $criteria): array
    {
        $query = TemplateType::query();
        foreach ($criteria as $field => $value) {
            $query->where($field, $value);
        }
        return $query->get()
            ->map(fn (TemplateType $model) => TemplateTypeMapper::toEntity($model))
            ->toArray();
    }

    public function save($entity): void
    {
        $model = $entity->getId() !== null ? TemplateType::find($entity->getId()) : null;
        $model = TemplateTypeMapper::toModel($entity, $model);
        $model->save();
    }

    public function delete(int $id): bool
    {
        return (bool) TemplateType::destroy($id);
    }
}
