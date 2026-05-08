<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Workday extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
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
