<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class FieldMapping extends Model
{
    /**
     * @var string[]
     */
    protected $fillable = [
        'alias_name',
        'target_field',
        'target_model',
    ];

    /**
     * Scope: filter mappings by target model.
     *
     * @param Builder $query
     * @param string  $model
     * @return Builder
     */
    public function scopeForModel(Builder $query, string $model): Builder
    {
        return $query->where('target_model', $model);
    }
}
