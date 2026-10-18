<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Units an outbound line took from an unvalued lot (delivery C, model 2.4); cost NULL until valued. */
class StockUnvaluedIssue extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'lot_id',
        'item_document_line_id',
        'quantity',
        'unit_cost',
        'total_cost',
        'reversed_by_line_id',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'lot_id' => 'integer',
        'item_document_line_id' => 'integer',
        'reversed_by_line_id' => 'integer',
    ];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockUnvaluedLot::class, 'lot_id')->withoutGlobalScopes();
    }
}
