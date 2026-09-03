<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceBatchDetail extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'batch_id',
        'female_category_id',
        'female_subcategory_id',
        'male_category_id',
        'target_bull_ratio',
        'planned_start_date',
        'planned_end_date',
        'notes',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'batch_id' => 'integer',
        'female_category_id' => 'integer',
        'female_subcategory_id' => 'integer',
        'male_category_id' => 'integer',
        'target_bull_ratio' => 'float',
        'planned_start_date' => 'date:Y-m-d',
        'planned_end_date' => 'date:Y-m-d',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function femaleCategory(): BelongsTo
    {
        return $this->belongsTo(AnimalCategory::class, 'female_category_id');
    }

    public function femaleSubcategory(): BelongsTo
    {
        return $this->belongsTo(AnimalSubcategory::class, 'female_subcategory_id');
    }

    public function maleCategory(): BelongsTo
    {
        return $this->belongsTo(AnimalCategory::class, 'male_category_id');
    }
}
