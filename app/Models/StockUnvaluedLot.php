<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Units in from a line of an unvalued delivery note (delivery C, model 2.2). Written only by the
 * ledger and by voiding provider documents.
 */
class StockUnvaluedLot extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'warehouse_id',
        'item_id',
        'delivery_note_line_id',
        'receipt_line_id',
        'quantity',
        'remaining_quantity',
        'status',
        'valued_unit_cost',
        'valued_quantity',
        'valued_by_line_id',
        'valuation_line_id',
        'valued_at',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'warehouse_id' => 'integer',
        'item_id' => 'integer',
        'delivery_note_line_id' => 'integer',
        'receipt_line_id' => 'integer',
        'valued_by_line_id' => 'integer',
        'valuation_line_id' => 'integer',
        'valued_at' => 'datetime',
    ];

    public function deliveryNoteLine(): BelongsTo
    {
        return $this->belongsTo(FiscalDocumentLine::class, 'delivery_note_line_id')->withoutGlobalScopes();
    }
}
