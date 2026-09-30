<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BirthOrder extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'code',
        'status',
        'kind',
        'period_start',
        'period_end',
        'planned_head_count',
        'requested_by_user_id',
        'emitted_at',
        'printed_at',
        'first_executed_at',
        'closed_at',
        'responsable',
        'observations',
        'closing_reason',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'planned_head_count' => 'integer',
        'requested_by_user_id' => 'integer',
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'emitted_at' => 'datetime',
        'printed_at' => 'datetime',
        'first_executed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function animals(): HasMany
    {
        return $this->hasMany(BirthOrderAnimal::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(BirthOrderHistory::class);
    }
}
