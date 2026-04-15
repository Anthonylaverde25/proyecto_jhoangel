<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Workday extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'work_date',
    ];

    protected $casts = [
        'work_date' => 'date',
    ];

    /**
     * Las caravanas procesadas en esta jornada.
     */
    public function caravans(): BelongsToMany
    {
        return $this->belongsToMany(Caravan::class, 'workday_caravan')
                    ->withTimestamps();
    }
}
