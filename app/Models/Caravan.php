<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use App\Core\Enums\AnimalCategory;

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
     * Get the breed associated with the caravan.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function breedRelation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Breed::class, 'breed_id');
    }
}
