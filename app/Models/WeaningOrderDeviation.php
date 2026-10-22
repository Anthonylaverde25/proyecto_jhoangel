<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeaningOrderDeviation extends Model
{
    protected $fillable = [
        'company_id',
        'weaning_order_id',
        'weaning_order_animal_id',
        'caravan_id',
        'kind',
        'planned',
        'actual',
        'metadata',
        'occurred_at',
        'recorded_by_user_id',
        'reviewed_at',
        'reviewed_by_user_id',
        'review_note',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'weaning_order_id' => 'integer',
        'weaning_order_animal_id' => 'integer',
        'caravan_id' => 'integer',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
        'recorded_by_user_id' => 'integer',
        'reviewed_at' => 'datetime',
        'reviewed_by_user_id' => 'integer',
    ];

    public function weaningOrder(): BelongsTo
    {
        return $this->belongsTo(WeaningOrder::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(WeaningOrderAnimal::class, 'weaning_order_animal_id');
    }

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
