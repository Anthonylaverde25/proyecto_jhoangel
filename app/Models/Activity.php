<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Activity extends Model
{
    protected $fillable = ['name', 'code', 'is_active', 'is_final'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_final' => 'boolean',
    ];

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_activity')
            ->withPivot('is_enabled')
            ->withTimestamps();
    }
}
