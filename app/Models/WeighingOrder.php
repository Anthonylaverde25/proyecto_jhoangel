<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeighingOrder extends Model
{
    protected $fillable = [
        'company_id', 'code', 'status', 'kind', 'mode', 'weighing_type_id', 'batch_id',
        'batch_entry', 'declared_head_count', 'declared_average_weight',
        'shrunk_condition', 'shrink_pct', 'weighing_date', 'weighing_time', 'planned_head_count',
        'requested_by_user_id', 'emitted_at', 'printed_at', 'first_executed_at', 'closed_at',
        'responsable', 'observations', 'closing_reason',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'weighing_type_id' => 'integer',
        'batch_id' => 'integer',
        'shrink_pct' => 'decimal:2',
        'declared_head_count' => 'integer',
        'declared_average_weight' => 'decimal:2',
        'weighing_date' => 'date',
        'planned_head_count' => 'integer',
        'requested_by_user_id' => 'integer',
        'emitted_at' => 'datetime',
        'printed_at' => 'datetime',
        'first_executed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function weighingType(): BelongsTo
    {
        return $this->belongsTo(WeighingType::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(WeighingOrderGroup::class)->orderBy('sequence');
    }

    public function animals(): HasMany
    {
        return $this->hasMany(WeighingOrderAnimal::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(WeighingOrderHistory::class);
    }
}
