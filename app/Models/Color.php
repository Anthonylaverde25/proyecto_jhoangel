<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Color extends Model
{
    protected $fillable = [
        'name',
        'code',
    ];

    /**
     * Breeds associated with this coat color.
     */
    public function breeds(): BelongsToMany
    {
        return $this->belongsToMany(Breed::class, 'breed_color');
    }

    /**
     * Caravans having this coat color.
     */
    public function caravans(): HasMany
    {
        return $this->hasMany(Caravan::class, 'color_id');
    }
}
