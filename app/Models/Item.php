<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'item_category_id',
        'unit_of_measure_id',
        'code',
        'name',
        'description',
        'type',
        'track_stock',
        'is_active',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'item_category_id' => 'integer',
        'unit_of_measure_id' => 'integer',
        'track_stock' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_of_measure_id')->withoutGlobalScopes();
    }

    public function presentations(): HasMany
    {
        return $this->hasMany(ItemPresentation::class)->orderBy('id');
    }

    public function providerLinks(): HasMany
    {
        return $this->hasMany(ItemProvider::class)->withoutGlobalScopes();
    }
}
