<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A difference between a delivery note and its invoice (delivery C, DC7). */
class FiscalDocumentDiscrepancy extends Model
{
    use BelongsToCompany;

    protected $table = 'fiscal_document_discrepancies';

    protected $fillable = [
        'company_id',
        'invoice_document_id',
        'delivery_note_id',
        'invoice_line_id',
        'delivery_note_line_id',
        'item_id',
        'type',
        'expected_value',
        'actual_value',
        'detail',
        'status',
        'resolution',
        'resolved_by_user_id',
        'resolved_at',
        'raised_by_user_id',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'invoice_document_id' => 'integer',
        'delivery_note_id' => 'integer',
        'invoice_line_id' => 'integer',
        'delivery_note_line_id' => 'integer',
        'item_id' => 'integer',
        'resolved_by_user_id' => 'integer',
        'raised_by_user_id' => 'integer',
        'resolved_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'invoice_document_id')->withoutGlobalScopes();
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'delivery_note_id')->withoutGlobalScopes();
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id')->withoutGlobalScopes();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
