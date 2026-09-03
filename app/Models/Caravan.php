<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use App\Core\Enums\AnimalSex;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Caravan extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'batch_id',
        'provider_id',
        'renspa',
        'provenance_metadata',
        'breed_id',
        'color_id',
        'identification',
        'category_id',
        'subcategory_id',
        'teeth',
        'entry_weight',
        'exit_weight',
        'sex',
        'entry_date',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'identification' => 'string',
        'category_id' => 'integer',
        'subcategory_id' => 'integer',
        'teeth' => 'integer',
        'entry_weight' => 'decimal:2',
        'exit_weight' => 'decimal:2',
        'entry_date' => 'date:Y-m-d',
        'batch_id' => 'integer',
        'provider_id' => 'integer',
        'renspa' => 'string',
        'provenance_metadata' => 'array',
        'breed_id' => 'integer',
        'color_id' => 'integer',
        'sex' => AnimalSex::class,
    ];

    /**
     * Get the provider associated with the caravan.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * Get the batch associated with the caravan.
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * Get the weight records for the caravan.
     */
    public function weights(): HasMany
    {
        return $this->hasMany(CaravanWeight::class);
    }

    /**
     * Get the current weight record for the caravan.
     */
    public function currentWeight(): HasOne
    {
        return $this->hasOne(CaravanWeight::class)->where('current', true);
    }

    /**
     * Get the breed associated with the caravan.
     */
    public function breedRelation(): BelongsTo
    {
        return $this->belongsTo(Breed::class, 'breed_id');
    }

    /**
     * Get the coat color associated with the caravan.
     */
    public function colorRelation(): BelongsTo
    {
        return $this->belongsTo(Color::class, 'color_id');
    }

    /**
     * Get the female details associated with the caravan.
     */
    public function femaleDetail(): HasOne
    {
        return $this->hasOne(FemaleCaravanDetail::class);
    }

    /**
     * Get the gestations associated with the caravan.
     */
    public function gestations(): HasMany
    {
        return $this->hasMany(CaravanGestation::class);
    }

    /**
     * Get the lineage of this caravan (parentage).
     */
    public function lineage(): HasOne
    {
        return $this->hasOne(CaravanLineage::class, 'caravan_id');
    }

    /**
     * Get the offspring of this caravan as a mother.
     */
    public function offspringAsMother(): HasMany
    {
        return $this->hasMany(CaravanLineage::class, 'mother_id');
    }

    /**
     * Get the offspring of this caravan as a father.
     */
    public function offspringAsFather(): HasMany
    {
        return $this->hasMany(CaravanLineage::class, 'father_id');
    }

    /**
     * Get the category master record for this caravan.
     */
    /**
     * Get the category master record for this caravan.
     */
    public function categoryRelation(): BelongsTo
    {
        return $this->belongsTo(\App\Models\AnimalCategory::class, 'category_id');
    }

    /**
     * Get the subcategory master record for this caravan.
     */
    public function subcategoryRelation(): BelongsTo
    {
        return $this->belongsTo(AnimalSubcategory::class, 'subcategory_id');
    }

    /**
     * Clinical diagnoses registered on this animal (universal: males and females).
     */
    public function diagnoses(): HasMany
    {
        return $this->hasMany(VeterinaryDiagnosis::class);
    }

    /**
     * Latest physical health evaluation for this bull.
     */
    public function bullHealthEvaluation(): HasOne
    {
        return $this->hasOne(BullHealthEvaluation::class)->latestOfMany('last_evaluation_date');
    }

    /**
     * Complete historical physical health evaluations for this bull.
     */
    public function bullHealthEvaluations(): HasMany
    {
        return $this->hasMany(BullHealthEvaluation::class);
    }

    /**
     * Complete laboratory samples (preputial scrapes & serology) for this bull.
     */
    public function bullLabSamples(): HasMany
    {
        return $this->hasMany(BullLabSample::class);
    }
}

