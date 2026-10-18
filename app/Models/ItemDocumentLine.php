<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A line of a stock operation: always a stock-tracked article.
 */
class ItemDocumentLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'item_document_id',
        'position',
        'item_id',
        'fiscal_document_line_id',
        'input_quantity',
        'input_unit_id',
        'input_presentation_id',
        'quantity',
        'input_unit_price',
        'unit_cost',
        'total_cost',
        'counted_quantity',
        'reverses_line_id',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'item_document_id' => 'integer',
        'position' => 'integer',
        'item_id' => 'integer',
        'fiscal_document_line_id' => 'integer',
        'input_unit_id' => 'integer',
        'input_presentation_id' => 'integer',
        'reverses_line_id' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withoutGlobalScopes();
    }

    public function inputUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'input_unit_id')->withoutGlobalScopes();
    }

    public function inputPresentation(): BelongsTo
    {
        return $this->belongsTo(ItemPresentation::class, 'input_presentation_id');
    }

    public function movement(): HasOne
    {
        return $this->hasOne(StockMovement::class)->withoutGlobalScopes();
    }
}
