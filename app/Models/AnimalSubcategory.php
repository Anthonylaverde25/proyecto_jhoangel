<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnimalSubcategory extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'animal_subcategories';

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'category_id',
        'code',
        'name',
        'target_weight_min',
        'target_weight_max',
        'description',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'category_id'       => 'integer',
        'target_weight_min' => 'decimal:2',
        'target_weight_max' => 'decimal:2',
    ];

    /**
     * Get the parent category.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AnimalCategory::class, 'category_id');
    }

    /**
     * Get the caravans belonging to this subcategory.
     */
    public function caravans(): HasMany
    {
        return $this->hasMany(Caravan::class, 'subcategory_id');
    }
}
