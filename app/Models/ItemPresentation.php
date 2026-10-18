<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Belongs to its item, so the company scope reaches it through the item.
 */
class ItemPresentation extends Model
{
    protected $fillable = [
        'item_id',
        'name',
        'factor_to_item_unit',
        'is_active',
    ];

    protected $casts = [
        'item_id' => 'integer',
        'factor_to_item_unit' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
