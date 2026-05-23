<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use App\Core\Enums\AnimalCategory;
use App\Core\Enums\AnimalSex;

class Caravan extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'batch_id',
        'breed_id',
        'identification',
        'category',
        'teeth',
        'entry_weight',
        'exit_weight',
        'breed',
        'sex',
        'entry_date',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'identification' => 'string',
        'category' => AnimalCategory::class,
        'teeth' => 'integer',
        'entry_weight' => 'decimal:2',
        'exit_weight' => 'decimal:2',
        'entry_date' => 'date:Y-m-d',
        'batch_id' => 'integer',
        'breed_id' => 'integer',
        'sex' => AnimalSex::class,
    ];

    /**
     * Get the batch associated with the caravan.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function batch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * Get the weight records for the caravan.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function weights(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CaravanWeight::class);
    }

    /**
     * Get the current weight record for the caravan.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function currentWeight(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CaravanWeight::class)->where('current', true);
    }

    /**
     * Get the breed associated with the caravan.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function breedRelation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Breed::class, 'breed_id');
    }

    /**
     * Get the female details associated with the caravan.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function femaleDetail(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(FemaleCaravanDetail::class);
    }

    /**
     * Get the gestations associated with the caravan.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function gestations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CaravanGestation::class);
    }

    /**
     * Get the lineage of this caravan (parentage).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function lineage(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CaravanLineage::class, 'caravan_id');
    }

    /**
     * Get the offspring of this caravan as a mother.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function offspringAsMother(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CaravanLineage::class, 'mother_id');
    }

    /**
     * Get the offspring of this caravan as a father.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function offspringAsFather(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CaravanLineage::class, 'father_id');
    }
}
