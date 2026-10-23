<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderInsemination extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'service_order_id',
        'female_caravan_id',
        'inseminated_on',
        'item_id',
        'semen_lot_id',
        'straws_used',
        'warehouse_id',
        'item_document_line_id',
        'notes',
        'voided_at',
        'void_reason',
        'created_by',
        'voided_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'service_order_id' => 'integer',
        'female_caravan_id' => 'integer',
        'inseminated_on' => 'date:Y-m-d',
        'item_id' => 'integer',
        'semen_lot_id' => 'integer',
        'straws_used' => 'integer',
        'warehouse_id' => 'integer',
        'item_document_line_id' => 'integer',
        'voided_at' => 'datetime',
        'created_by' => 'integer',
        'voided_by' => 'integer',
    ];

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function femaleCaravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class, 'female_caravan_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function semenLot(): BelongsTo
    {
        return $this->belongsTo(SemenLot::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ItemDocumentLine::class, 'item_document_line_id');
    }
}
