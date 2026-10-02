<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntryOrderAnimal extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'entry_order_id',
        'entry_order_dte_id',
        'caravan_id',
        'entry_order_breed_id',
        'caravan_movement_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'entry_order_id' => 'integer',
        'entry_order_dte_id' => 'integer',
        'caravan_id' => 'integer',
        'entry_order_breed_id' => 'integer',
        'caravan_movement_id' => 'integer',
    ];

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }

    public function breedLine(): BelongsTo
    {
        return $this->belongsTo(EntryOrderBreed::class, 'entry_order_breed_id');
    }
}
