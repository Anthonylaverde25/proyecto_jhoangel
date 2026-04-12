<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Enums\AnimalCategory;

class Caravan extends Model
{
    /**
     * @var string[]
     */
    protected $fillable = [
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
    ];
}
