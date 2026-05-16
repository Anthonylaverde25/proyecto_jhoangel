<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Enums\AnimalCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FemaleCaravanDetail extends Model
{
    /**
     * @var string[]
     */
    protected $fillable = [
        'caravan_id',
        'is_empty',
        'arrival_category',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_empty' => 'boolean',
        'arrival_category' => AnimalCategory::class,
    ];

    /**
     * Get the caravan that owns the female details.
     */
    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }
}
