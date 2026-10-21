<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The death of an animal: when, why, and the death batch it went to.
 */
class CaravanDeath extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'caravan_id',
        'death_date',
        'death_cause_id',
        'observations',
        'from_batch_id',
        'death_batch_id',
        'caravan_movement_id',
        'registered_by_user_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'caravan_id' => 'integer',
        'death_date' => 'date:Y-m-d',
        'death_cause_id' => 'integer',
        'from_batch_id' => 'integer',
        'death_batch_id' => 'integer',
        'caravan_movement_id' => 'integer',
        'registered_by_user_id' => 'integer',
    ];

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }

    public function cause(): BelongsTo
    {
        return $this->belongsTo(DeathCause::class, 'death_cause_id');
    }
}
