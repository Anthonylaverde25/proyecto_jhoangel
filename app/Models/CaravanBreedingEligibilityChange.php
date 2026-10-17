<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One owner decision on an animal's breeding eligibility (audit trail).
 */
class CaravanBreedingEligibilityChange extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'caravan_id',
        'is_breeding_eligible',
        'reason',
        'note',
        'changed_by_user_id',
        'changed_at',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'caravan_id' => 'integer',
        'is_breeding_eligible' => 'boolean',
        'changed_by_user_id' => 'integer',
        'changed_at' => 'datetime',
    ];

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
