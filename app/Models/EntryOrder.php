<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntryOrder extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'code',
        'number',
        'status',
        'kind',
        'provider_id',
        'farm_id',
        'auction_number',
        'batch_id',
        'batch_name',
        'batch_name_mode',
        'head_count',
        'category_id',
        'sex_composition',
        'male_count',
        'female_count',
        'condition',
        'age_min_months',
        'age_max_months',
        'knows_to_eat',
        'tick_vaccinated',
        'shrink_percent',
        'estimated_weight',
        'min_weight',
        'max_weight',
        'purchase_date',
        'requested_by_user_id',
        'confirmed_at',
        'printed_at',
        'first_dte_at',
        'closed_at',
        'responsable',
        'observations',
        'closing_reason',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'number' => 'integer',
        'provider_id' => 'integer',
        'farm_id' => 'integer',
        'batch_id' => 'integer',
        'head_count' => 'integer',
        'category_id' => 'integer',
        'male_count' => 'integer',
        'female_count' => 'integer',
        'age_min_months' => 'integer',
        'age_max_months' => 'integer',
        'knows_to_eat' => 'boolean',
        'tick_vaccinated' => 'boolean',
        'shrink_percent' => 'float',
        'estimated_weight' => 'float',
        'min_weight' => 'float',
        'max_weight' => 'float',
        'purchase_date' => 'date:Y-m-d',
        'requested_by_user_id' => 'integer',
        'confirmed_at' => 'datetime',
        'printed_at' => 'datetime',
        'first_dte_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AnimalCategory::class, 'category_id');
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function breeds(): HasMany
    {
        return $this->hasMany(EntryOrderBreed::class)->orderBy('position');
    }

    public function dtes(): HasMany
    {
        return $this->hasMany(EntryOrderDte::class)->orderBy('id');
    }

    public function animals(): HasMany
    {
        return $this->hasMany(EntryOrderAnimal::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(EntryOrderHistory::class)->orderBy('id');
    }
}
