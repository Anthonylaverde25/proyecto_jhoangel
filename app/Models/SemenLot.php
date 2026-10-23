<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SemenLot extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'item_id',
        'code',
        'collection_date',
        'collection_code',
        'notes',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'item_id' => 'integer',
        'collection_date' => 'date',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(SemenLotStock::class);
    }
}
