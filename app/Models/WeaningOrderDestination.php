<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeaningOrderDestination extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'weaning_order_id',
        'destination_key',
        'label',
        'target_batch_id',
        'new_batch_name',
        'is_confined',
        'resolved_batch_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'weaning_order_id' => 'integer',
        'target_batch_id' => 'integer',
        'is_confined' => 'boolean',
        'resolved_batch_id' => 'integer',
    ];

    public function weaningOrder(): BelongsTo
    {
        return $this->belongsTo(WeaningOrder::class);
    }

    public function targetBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'target_batch_id');
    }

    public function resolvedBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'resolved_batch_id');
    }
}
