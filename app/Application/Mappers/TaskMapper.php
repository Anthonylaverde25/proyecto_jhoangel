<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\TaskEntity;
use App\Core\Enums\TaskUrgency;
use App\Core\ValueObjects\TaskProviderRef;
use App\Models\Provider;
use App\Models\Task;
use DateTimeImmutable;

final class TaskMapper
{
    public static function toDomain(Task $model): TaskEntity
    {
        $providers = $model->relationLoaded('providers')
            ? $model->providers
                ->map(fn (Provider $provider): TaskProviderRef => new TaskProviderRef((int) $provider->id, (string) $provider->name))
                ->values()
                ->all()
            : [];

        return new TaskEntity(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            description: (string) $model->description,
            urgency: TaskUrgency::tryFrom((string) $model->urgency) ?? TaskUrgency::default(),
            providers: $providers,
            createdAt: $model->created_at ? new DateTimeImmutable((string) $model->created_at) : null,
            updatedAt: $model->updated_at ? new DateTimeImmutable((string) $model->updated_at) : null,
        );
    }
}
