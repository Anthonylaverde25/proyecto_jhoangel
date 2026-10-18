<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemCategory extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'parent_id',
        'code',
        'name',
        'is_active',
        'display_order',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'parent_id' => 'integer',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'item_category_id');
    }
}
