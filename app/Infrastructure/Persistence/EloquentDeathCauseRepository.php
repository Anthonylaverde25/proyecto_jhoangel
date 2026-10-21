<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Interfaces\IDeathCauseRepository;
use App\Models\DeathCause;

final class EloquentDeathCauseRepository implements IDeathCauseRepository
{
    public function findActive(): array
    {
        return DeathCause::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'description'])
            ->map(fn (DeathCause $cause) => [
                'id' => (int) $cause->id,
                'code' => (string) $cause->code,
                'name' => (string) $cause->name,
                'description' => $cause->description,
            ])
            ->all();
    }

    public function findActiveById(int $id): ?array
    {
        $cause = DeathCause::query()->where('is_active', true)->find($id, ['id', 'code', 'name']);

        return $cause !== null ? ['id' => (int) $cause->id, 'code' => (string) $cause->code, 'name' => (string) $cause->name] : null;
    }
}
