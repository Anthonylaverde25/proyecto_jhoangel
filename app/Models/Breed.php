<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Breed extends Model
{
    protected $fillable = ['name'];

    /**
     * Coat colors associated with this breed.
     */
    public function colors(): BelongsToMany
    {
        return $this->belongsToMany(Color::class, 'breed_color');
    }

    /**
     * Caravans belonging to this breed.
     */
    public function caravans(): HasMany
    {
        return $this->hasMany(Caravan::class, 'breed_id');
    }
}
