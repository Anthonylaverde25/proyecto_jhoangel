<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A body condition score of a caravan (official scale 1 to 5), with the record it came from.
 */
class CaravanBodyCondition extends Model
{
    public const SOURCE_ENTRY_RECEPTION = 'ENTRY_RECEPTION';
    public const SOURCE_MANUAL = 'MANUAL';

    protected $fillable = [
        'caravan_id',
        'score',
        'current',
        'assessed_at',
        'source',
        'entry_order_receipt_sheet_id',
        'notes',
    ];

    protected $casts = [
        'caravan_id' => 'integer',
        'score' => 'decimal:1',
        'current' => 'boolean',
        'assessed_at' => 'date',
        'entry_order_receipt_sheet_id' => 'integer',
    ];

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }

    public function receiptSheet(): BelongsTo
    {
        return $this->belongsTo(EntryOrderReceiptSheet::class, 'entry_order_receipt_sheet_id');
    }
}
