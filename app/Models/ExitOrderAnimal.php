<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One animal of an exit order, with the snapshot of where it was when it left. */
class ExitOrderAnimal extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'exit_order_id',
        'caravan_id',
        'status',
        'from_batch_id',
        'activity_id',
        'category_id',
        'was_pregnant',
        'weight_kg',
        'weight_estimated',
        'price_per_kg',
        'amount',
        'exited_at',
        'caravan_movement_id',
        'caravan_weight_id',
        'skipped_reason',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'exit_order_id' => 'integer',
        'caravan_id' => 'integer',
        'from_batch_id' => 'integer',
        'activity_id' => 'integer',
        'category_id' => 'integer',
        'was_pregnant' => 'boolean',
        'weight_kg' => 'float',
        'weight_estimated' => 'boolean',
        'price_per_kg' => 'float',
        'amount' => 'float',
        'exited_at' => 'datetime',
        'caravan_movement_id' => 'integer',
        'caravan_weight_id' => 'integer',
    ];

    public function exitOrder(): BelongsTo
    {
        return $this->belongsTo(ExitOrder::class);
    }

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class)->withoutGlobalScopes();
    }

    public function fromBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'from_batch_id')->withoutGlobalScopes();
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AnimalCategory::class, 'category_id');
    }
}
