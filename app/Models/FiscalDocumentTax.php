<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tax charged on a whole provider document, such as a perception (DS16).
 */
class FiscalDocumentTax extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'fiscal_document_id',
        'tax_id',
        'rate',
        'taxable_base',
        'amount',
        'is_recoverable',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'fiscal_document_id' => 'integer',
        'tax_id' => 'integer',
        'is_recoverable' => 'boolean',
    ];

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
