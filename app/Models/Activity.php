<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Activity extends Model
{
    protected $fillable = ['name', 'code', 'is_active'];

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_activity')
            ->withPivot('is_enabled')
            ->withTimestamps();
    }
}
