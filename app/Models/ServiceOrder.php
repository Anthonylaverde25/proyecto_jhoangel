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
        'parent_service_order_id',
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
        'diagnosis_completed_at',
        'target_bull_ratio',
        'final_pregnancy_rate',
        'observations',
        'rejection_reason',
        'service_type',
        'service_method',
        'is_controlled_service',
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
        'planned_end_date' => 'date:Y-m-d',
        'actual_start_date' => 'date:Y-m-d',
        'actual_end_date' => 'date:Y-m-d',
        'diagnosis_completed_at' => 'date:Y-m-d',
        'parent_service_order_id' => 'integer',
        'target_bull_ratio' => 'float',
        'final_pregnancy_rate' => 'float',
        'is_controlled_service' => 'boolean',
    ];

    /**
     * The perennial operational batch the service runs on.
     */
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
            ->withPivot(['status', 'joined_at', 'retired_at', 'snapshot_scrotal_circumference', 'service_capacity'])
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
            ->withPivot([
                'assigned_male_caravan_id',
                'exposed_from',
                'exposed_until',
                'exit_reason',
                'joined_after_start',
                'reproductive_status',
                'diagnosed_at',
                'planned_semen_item_id',
                'not_inseminated_reason',
            ])
            ->withTimestamps();
    }

    /** Straw applications of an AI order (semen S-B), voided ones included. */
    public function inseminations(): HasMany
    {
        return $this->hasMany(ServiceOrderInsemination::class, 'service_order_id');
    }

    /** Latest live insemination per female, for the diagnosis rules of an AI order (IA11, IA12). */
    public function lastInseminations(): HasMany
    {
        return $this->hasMany(ServiceOrderInsemination::class, 'service_order_id')
            ->whereNull('voided_at')
            ->selectRaw('service_order_id, female_caravan_id, MAX(inseminated_on) as last_inseminated_on')
            ->groupBy('service_order_id', 'female_caravan_id');
    }

    public function diagnosisRounds(): HasMany
    {
        return $this->hasMany(ServiceOrderDiagnosisRound::class)->orderBy('round_date');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(ServiceOrderIncident::class)->orderByDesc('id');
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

