<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaravanMovement extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'caravan_id',
        'company_id',
        'renspa',
        'type',
        'movement_date',
        'observations',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'movement_date' => 'datetime',
        'caravan_id' => 'integer',
        'company_id' => 'integer',
    ];

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }
}
