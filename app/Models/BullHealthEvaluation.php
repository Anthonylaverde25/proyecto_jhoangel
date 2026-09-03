<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BullHealthEvaluation extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'caravan_id',
        'last_evaluation_date',
        'aplomo_notes',
        'scrotal_circumference_cm',
        'body_condition_score',
        'libido',
        'status',
        'observations',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'caravan_id' => 'integer',
        'last_evaluation_date' => 'date:Y-m-d',
        'scrotal_circumference_cm' => 'float',
        'body_condition_score' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }

    public function labSamples(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BullLabSample::class, 'evaluation_id');
    }
}
