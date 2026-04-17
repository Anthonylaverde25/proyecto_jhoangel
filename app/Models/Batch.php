<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Batch extends Model
{
    protected $fillable = [
        'name',
        'farm_id',
        'observaciones',
        'is_active',
    ];

    protected $casts = [
        'farm_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function caravans(): HasMany
    {
        return $this->hasMany(Caravan::class);
    }
}
