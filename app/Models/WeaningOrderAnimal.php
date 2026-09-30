<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeaningOrderAnimal extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'weaning_order_id',
        'caravan_id',
        'source_batch_id',
        'weaning_order_destination_id',
        'target_category_id',
        'target_subcategory_id',
        'status',
        'weaned_at',
        'caravan_movement_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'weaning_order_id' => 'integer',
        'caravan_id' => 'integer',
        'source_batch_id' => 'integer',
        'weaning_order_destination_id' => 'integer',
        'target_category_id' => 'integer',
        'target_subcategory_id' => 'integer',
        'caravan_movement_id' => 'integer',
        'weaned_at' => 'datetime',
    ];

    public function weaningOrder(): BelongsTo
    {
        return $this->belongsTo(WeaningOrder::class);
    }

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }

    public function sourceBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'source_batch_id');
    }

    public function targetCategory(): BelongsTo
    {
        return $this->belongsTo(AnimalCategory::class, 'target_category_id');
    }

    public function targetSubcategory(): BelongsTo
    {
        return $this->belongsTo(AnimalSubcategory::class, 'target_subcategory_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(WeaningOrderDestination::class, 'weaning_order_destination_id');
    }
}
