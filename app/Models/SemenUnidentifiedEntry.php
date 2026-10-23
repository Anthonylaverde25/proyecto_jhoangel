<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SemenUnidentifiedEntry extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'item_id',
        'warehouse_id',
        'item_document_line_id',
        'stock_incident_id',
        'direction',
        'quantity',
        'identified_quantity',
        'status',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'item_id' => 'integer',
        'warehouse_id' => 'integer',
        'item_document_line_id' => 'integer',
        'stock_incident_id' => 'integer',
        'direction' => 'integer',
    ];

    public function identifications(): HasMany
    {
        return $this->hasMany(SemenLotIdentification::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ItemDocumentLine::class, 'item_document_line_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
