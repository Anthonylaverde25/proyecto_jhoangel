<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BatchType extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'color',
        'icon',
        'is_active',
        'is_selectable',
        'activity_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_selectable' => 'boolean',
        'activity_id' => 'integer',
    ];

    /**
     * Catalogue constraint: the activity this type is restricted to.
     * Null means the type is cross-cutting and offered in every activity.
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_batch_type')
            ->withPivot(['is_enabled', 'custom_name', 'custom_color'])
            ->withTimestamps();
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->whereHas('companies', function ($q) use ($companyId) {
            $q->where('companies.id', $companyId)
              ->where('company_batch_type.is_enabled', true);
        });
    }

    public function scopeSelectable($query)
    {
        return $query->where('is_selectable', true);
    }

    public function scopeByCode($query, $code)
    {
        return $query->where('code', $code);
    }

    public function isOperational(): bool
    {
        return $this->code === 'OPERATIONAL';
    }

    public function isQuarantine(): bool
    {
        return $this->code === 'QUARANTINE';
    }

    public function isService(): bool
    {
        return $this->code === 'SERVICE';
    }

    public function isWeaning(): bool
    {
        return $this->code === 'WEANING';
    }
}
