<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Farm extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'renspa',
        'location',
        'provider_id',
        'is_active',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'provider_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function scopeOwn($query)
    {
        return $query->whereNull('provider_id');
    }

    public function scopeExternal($query)
    {
        return $query->whereNotNull('provider_id');
    }
}

