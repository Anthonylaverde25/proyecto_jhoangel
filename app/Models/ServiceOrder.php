<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceOrder extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'batch_id',
        'code',
        'status',
        'requested_by_user_id',
        'reviewed_by_user_id',
        'approved_by_user_id',
        'reviewed_at',
        'approved_at',
        'executed_at',
        'planned_start_date',
        'actual_start_date',
        'actual_end_date',
        'observations',
        'rejection_reason',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'batch_id' => 'integer',
        'requested_by_user_id' => 'integer',
        'reviewed_by_user_id' => 'integer',
        'approved_by_user_id' => 'integer',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
        'planned_start_date' => 'date:Y-m-d',
        'actual_start_date' => 'date:Y-m-d',
        'actual_end_date' => 'date:Y-m-d',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function males(): BelongsToMany
    {
        return $this->belongsToMany(Caravan::class, 'service_order_males', 'service_order_id', 'male_caravan_id')
            ->withTimestamps();
    }

    public function females(): BelongsToMany
    {
        return $this->belongsToMany(Caravan::class, 'service_order_females', 'service_order_id', 'female_caravan_id')
            ->withTimestamps();
    }

    public function history(): HasMany
    {
        return $this->hasMany(ServiceOrderHistory::class);
    }

    public function gestations(): HasMany
    {
        return $this->hasMany(CaravanGestation::class);
    }
}
