<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaravanCategoryChange extends Model
{
    use BelongsToCompany;

    protected $table = 'caravan_category_changes';

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'caravan_id',
        'from_category_id',
        'from_subcategory_id',
        'to_category_id',
        'to_subcategory_id',
        'cause',
        'occurred_on',
        'source_type',
        'source_id',
        'registered_by_user_id',
    ];

    protected $casts = [
        'occurred_on' => 'date:Y-m-d',
        'created_at' => 'datetime',
    ];

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class, 'caravan_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function fromCategory(): BelongsTo
    {
        return $this->belongsTo(AnimalCategory::class, 'from_category_id');
    }

    public function fromSubcategory(): BelongsTo
    {
        return $this->belongsTo(AnimalSubcategory::class, 'from_subcategory_id');
    }

    public function toCategory(): BelongsTo
    {
        return $this->belongsTo(AnimalCategory::class, 'to_category_id');
    }

    public function toSubcategory(): BelongsTo
    {
        return $this->belongsTo(AnimalSubcategory::class, 'to_subcategory_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }
}

