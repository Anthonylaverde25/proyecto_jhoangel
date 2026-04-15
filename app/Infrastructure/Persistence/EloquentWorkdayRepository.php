<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\WorkdayMapper;
use App\Core\Entities\WorkdayEntity;
use App\Core\Interfaces\IWorkdayRepository;
use App\Models\Workday;

class EloquentWorkdayRepository implements IWorkdayRepository
{
    public function save(WorkdayEntity $workday): WorkdayEntity
    {
        $model = $workday->getId() !== null ? Workday::find($workday->getId()) : null;
        $model = WorkdayMapper::toModel($workday, $model);
        $model->save();

        return WorkdayMapper::toEntity($model);
    }
    
    public function findById(int $id): ?WorkdayEntity
    {
        $model = Workday::find($id);
        
        return $model ? WorkdayMapper::toEntity($model) : null;
    }
    
    public function findByCode(string $code): ?WorkdayEntity
    {
        $model = Workday::where('code', $code)->first();
        
        return $model ? WorkdayMapper::toEntity($model) : null;
    }

    public function getLastCodeForDate(\DateTimeInterface $date): ?string
    {
        return Workday::whereDate('work_date', $date->format('Y-m-d'))
            ->orderBy('code', 'desc')
            ->value('code');
    }
}
