<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A stock operation (DS14). Append-only.
 */
class ItemDocument extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'number',
        'type',
        'warehouse_id',
        'fiscal_document_id',
        'batch_id',
        'heads_treated',
        'occurred_at',
        'reverses_document_id',
        'paired_document_id',
        'reason',
        'notes',
        'idempotency_key',
        'created_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'number' => 'integer',
        'warehouse_id' => 'integer',
        'fiscal_document_id' => 'integer',
        'batch_id' => 'integer',
        'heads_treated' => 'integer',
        'reverses_document_id' => 'integer',
        'paired_document_id' => 'integer',
        'created_by' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(ItemDocumentLine::class)->withoutGlobalScopes()->orderBy('position');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withoutGlobalScopes();
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class)->withoutGlobalScopes();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class)->withoutGlobalScopes();
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_document_id')->withoutGlobalScopes();
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_document_id')->withoutGlobalScopes();
    }

    /** The exit a TRANSFER_IN points to (DD7). */
    public function paired(): BelongsTo
    {
        return $this->belongsTo(self::class, 'paired_document_id')->withoutGlobalScopes();
    }

    /** The entry that points to this TRANSFER_OUT. */
    public function pairedBy(): HasOne
    {
        return $this->hasOne(self::class, 'paired_document_id')->withoutGlobalScopes();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
