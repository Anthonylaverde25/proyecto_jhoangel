<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnitOfMeasure extends Model
{
    use BelongsToCompany;

    protected $table = 'units_of_measure';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'symbol',
        'dimension',
        'factor_to_base',
        'is_base',
        'is_active',
        'display_order',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'factor_to_base' => 'decimal:10',
        'is_base' => 'boolean',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'unit_of_measure_id');
    }
}
