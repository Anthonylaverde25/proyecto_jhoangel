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
        'origin_batch_id',
        'service_batch_id',
        'code',
        'status',
        'requested_by_user_id',
        'reviewed_by_user_id',
        'approved_by_user_id',
        'reviewed_at',
        'approved_at',
        'executed_at',
        'planned_start_date',
        'planned_end_date',
        'actual_start_date',
        'actual_end_date',
        'target_bull_ratio',
        'final_pregnancy_rate',
        'observations',
        'rejection_reason',
        'service_type',
        'is_controlled_service',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'batch_id' => 'integer',
        'origin_batch_id' => 'integer',
        'service_batch_id' => 'integer',
        'requested_by_user_id' => 'integer',
        'reviewed_by_user_id' => 'integer',
        'approved_by_user_id' => 'integer',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
        'planned_start_date' => 'date:Y-m-d',
        'planned_end_date' => 'date:Y-m-d',
        'actual_start_date' => 'date:Y-m-d',
        'actual_end_date' => 'date:Y-m-d',
        'target_bull_ratio' => 'float',
        'final_pregnancy_rate' => 'float',
        'is_controlled_service' => 'boolean',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function originBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'origin_batch_id');
    }

    public function serviceBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'service_batch_id');
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
            ->withPivot(['status', 'retired_at', 'snapshot_scrotal_circumference', 'service_capacity'])
            ->withTimestamps();
    }

    public function serviceOrderMales(): HasMany
    {
        return $this->hasMany(ServiceOrderMale::class, 'service_order_id');
    }

    public function bullReplacements(): HasMany
    {
        return $this->hasMany(ServiceOrderBullReplacement::class, 'service_order_id')
            ->orderByDesc('replacement_date');
    }

    public function females(): BelongsToMany
    {
        return $this->belongsToMany(Caravan::class, 'service_order_females', 'service_order_id', 'female_caravan_id')
            ->withPivot('assigned_male_caravan_id')
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

