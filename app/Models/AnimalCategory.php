<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnimalCategory extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'animal_categories';

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'code',
        'name',
        'sex',
        'min_age_months',
        'max_age_months',
        'min_weight_kg',
        'max_weight_kg',
        'is_reproductive',
        'description',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'min_age_months' => 'integer',
        'max_age_months' => 'integer',
        'min_weight_kg'  => 'decimal:2',
        'max_weight_kg'  => 'decimal:2',
        'is_reproductive' => 'boolean',
    ];

    /**
     * Get the subcategories belonging to this category.
     */
    public function subcategories(): HasMany
    {
        return $this->hasMany(AnimalSubcategory::class, 'category_id');
    }

    /**
     * Get the caravans belonging to this category.
     */
    public function caravans(): HasMany
    {
        return $this->hasMany(Caravan::class, 'category_id');
    }
}
