<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Batch extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'farm_id',
        'activity_id',
        'current_weight',
        'total_weight',
        'caravans_count',
        'weighed_count',
        'min_weight',
        'max_weight',
        'knows_to_eat',
        'is_confined',
        'age_in_months',
        'observaciones',
        'is_active',
        'is_system',
        'batch_type_id'
    ];

    protected $casts = [
        'farm_id' => 'integer',
        'activity_id' => 'integer',
        'current_weight' => 'float',
        'total_weight' => 'float',
        'caravans_count' => 'integer',
        'weighed_count' => 'integer',
        'min_weight' => 'float',
        'max_weight' => 'float',
        'knows_to_eat' => 'boolean',
        'is_confined' => 'boolean',
        'age_in_months' => 'integer',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function caravans(): HasMany
    {
        return $this->hasMany(Caravan::class);
    }

    public function batchType(): BelongsTo
    {
        return $this->belongsTo(BatchType::class);
    }

    /**
     * Movements that took animals OUT of this batch. Distinguishes a batch that was
     * emptied by a transfer from one that never held animals at all.
     */
    public function outgoingMovements(): HasMany
    {
        return $this->hasMany(CaravanMovement::class, 'from_batch_id');
    }

    /**
     * Head of entry orders received by count on this batch and still waiting for their caravan:
     * they are in the batch, but caravans_count does not see them because their caravans do not
     * exist yet. Adds `pending_identification_count` without dropping the columns already selected.
     */
    public function scopeWithPendingIdentification(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select($this->getTable() . '.*');
        }

        return $query->addSelect([
            'pending_identification_count' => EntryOrderPendingHead::query()
                ->selectRaw('COALESCE(SUM(head_count), 0)')
                ->whereColumn('entry_order_pending_heads.batch_id', $this->getTable() . '.id'),
        ]);
    }

    /**
     * Calves at foot (unweaned, `caravan_lineage.is_nursing`) the batch holds: they are born into
     * their mother's batch, so its head and its mean weight mix them with the adults. Adds
     * `nursing_count`, `nursing_weighed_count` and `nursing_total_weight`, read with the same
     * definition as the batch aggregates (caravans in possession, their current weight), so the
     * adults are exactly the rest. Also `untagged_calf_count`: the calves still waiting for their tag.
     */
    public function scopeWithNursingCalves(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select($this->getTable() . '.*');
        }

        $nursing = fn () => Caravan::query()->inPossession()
            ->join('caravan_lineage', 'caravan_lineage.caravan_id', '=', 'caravans.id')
            ->where('caravan_lineage.is_nursing', true)
            ->whereColumn('caravans.batch_id', $this->getTable() . '.id');
        $weighed = fn () => $nursing()
            ->join('caravan_weights', 'caravan_weights.caravan_id', '=', 'caravans.id')
            ->where('caravan_weights.current', true);

        return $query->addSelect([
            'nursing_count' => $nursing()->selectRaw('COUNT(*)'),
            'nursing_weighed_count' => $weighed()->selectRaw('COUNT(*)'),
            'nursing_total_weight' => $weighed()->selectRaw('COALESCE(SUM(caravan_weights.weight), 0)'),
            // Calves born here that still wait for their tag: already caravans, already head.
            'untagged_calf_count' => Caravan::query()
                ->whereNull('caravans.identification')
                ->whereDoesntHave('death')
                ->whereColumn('caravans.batch_id', $this->getTable() . '.id')
                ->selectRaw('COUNT(*)'),
        ]);
    }

    public function pendingHeads(): HasMany
    {
        return $this->hasMany(EntryOrderPendingHead::class);
    }

    public function scopeOperational($query)
    {
        return $query->whereHas('batchType', function ($q) {
            $q->where('code', 'OPERATIONAL');
        });
    }

    public function scopeQuarantine($query)
    {
        return $query->whereHas('batchType', function ($q) {
            $q->where('code', 'QUARANTINE');
        });
    }

    public function scopeReserve($query)
    {
        return $query->whereHas('batchType', function ($q) {
            $q->where('code', 'RESERVE');
        });
    }

    public function scopeWeaning($query)
    {
        return $query->whereHas('batchType', function ($q) {
            $q->where('code', 'WEANING');
        });
    }

    public function isInQuarantine(): bool
    {
        return $this->batchType?->code === 'QUARANTINE' ?? false;
    }

    public function isWeaningBatch(): bool
    {
        return $this->batchType?->code === 'WEANING' ?? false;
    }

    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }

    /**
     * Every service the batch went through (1 to N campaigns), newest first.
     */
    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class, 'batch_id')
            ->orderByDesc('planned_start_date');
    }

    /**
     * The service running on the batch, if any (DRAFT or APPROVED: at most one, enforced by index).
     */
    public function activeServiceOrder(): HasOne
    {
        return $this->hasOne(ServiceOrder::class, 'batch_id')
            ->whereIn('status', ['DRAFT', 'APPROVED']);
    }

    public function isInService(): bool
    {
        return $this->activeServiceOrder()->exists();
    }
}

