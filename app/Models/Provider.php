<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'commercial_name',
        'cuit',
        'location',
        'email',
        'phone',
        'is_active',
        'supplies_livestock',
        'supplies_items',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'is_active' => 'boolean',
        'supplies_livestock' => 'boolean',
        'supplies_items' => 'boolean',
    ];

    public function farms(): HasMany
    {
        return $this->hasMany(Farm::class);
    }
}
