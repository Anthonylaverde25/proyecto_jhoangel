<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A provider document as on paper (DS14).
 */
class FiscalDocument extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'provider_id',
        'fiscal_document_type_id',
        'point_of_sale',
        'number',
        'reference',
        'issued_at',
        'related_fiscal_document_id',
        'lines_amount',
        'line_taxes_amount',
        'document_taxes_amount',
        'total_amount',
        'declared_total',
        'status',
        'void_reason',
        'voided_by',
        'voided_at',
        'notes',
        'idempotency_key',
        'created_by',
        'is_valued',
        'invoiced_by_document_id',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'provider_id' => 'integer',
        'fiscal_document_type_id' => 'integer',
        'point_of_sale' => 'integer',
        'number' => 'integer',
        'related_fiscal_document_id' => 'integer',
        'voided_by' => 'integer',
        'created_by' => 'integer',
        'is_valued' => 'boolean',
        'invoiced_by_document_id' => 'integer',
        'issued_at' => 'date:Y-m-d',
        'voided_at' => 'datetime',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(FiscalDocumentType::class, 'fiscal_document_type_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class)->withoutGlobalScopes();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FiscalDocumentLine::class)->withoutGlobalScopes()->orderBy('position');
    }

    public function documentTaxes(): HasMany
    {
        return $this->hasMany(FiscalDocumentTax::class)->withoutGlobalScopes()->orderBy('id');
    }

    public function related(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_fiscal_document_id')->withoutGlobalScopes();
    }

    /** The stock operation it generated, not its reversal. */
    public function stockOperation(): HasOne
    {
        return $this->hasOne(ItemDocument::class)->withoutGlobalScopes()->where('type', '!=', 'REVERSAL');
    }

    /** A delivery note: the invoice it was converted into (delivery C, DC10). */
    public function invoicedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invoiced_by_document_id')->withoutGlobalScopes();
    }

    /** An invoice: the delivery notes converted into it (DC5). */
    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(self::class, 'invoiced_by_document_id')->withoutGlobalScopes();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
