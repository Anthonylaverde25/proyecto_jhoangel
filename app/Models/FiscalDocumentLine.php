<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A line of a provider document, converted to the item's unit, with its cost.
 */
class FiscalDocumentLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'fiscal_document_id',
        'position',
        'item_id',
        'input_quantity',
        'input_unit_id',
        'input_presentation_id',
        'quantity',
        'unit_price',
        'line_amount',
        'non_recoverable_tax_amount',
        'cost_amount',
        'unit_cost',
        'batch_id',
        'heads_treated',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'fiscal_document_id' => 'integer',
        'position' => 'integer',
        'item_id' => 'integer',
        'input_unit_id' => 'integer',
        'input_presentation_id' => 'integer',
        'batch_id' => 'integer',
        'heads_treated' => 'integer',
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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class)->withoutGlobalScopes();
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(FiscalDocumentLineTax::class)->withoutGlobalScopes()->orderBy('id');
    }
}
