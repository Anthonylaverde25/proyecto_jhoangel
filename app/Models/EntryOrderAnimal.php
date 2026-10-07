<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'received_at',
        'reception_method',
        'received_by_user_id',
        'entry_order_breed_id',
        'entry_order_category_id',
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
        'received_at' => 'date:Y-m-d',
        'received_by_user_id' => 'integer',
        'entry_order_breed_id' => 'integer',
        'entry_order_category_id' => 'integer',
        'caravan_movement_id' => 'integer',
    ];

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }

    public function dte(): BelongsTo
    {
        return $this->belongsTo(EntryOrderDte::class, 'entry_order_dte_id');
    }

    public function entryOrder(): BelongsTo
    {
        return $this->belongsTo(EntryOrder::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function breedLine(): BelongsTo
    {
        return $this->belongsTo(EntryOrderBreed::class, 'entry_order_breed_id');
    }

    public function categoryLine(): BelongsTo
    {
        return $this->belongsTo(EntryOrderCategory::class, 'entry_order_category_id');
    }

    /**
     * What the chute saw on it as it came off the truck.
     */
    public function arrivalFindings(): HasMany
    {
        return $this->hasMany(EntryOrderArrivalFinding::class, 'entry_order_animal_id');
    }
}
