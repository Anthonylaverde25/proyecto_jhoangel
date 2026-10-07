<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A box marked on a reception line: what the chute saw on the animal as it came off the truck.
 */
class EntryOrderArrivalFinding extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'entry_order_animal_id',
        'caravan_id',
        'entry_order_dte_id',
        'finding',
        'observed_at',
        'entry_order_receipt_sheet_id',
        'recorded_by_user_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'entry_order_animal_id' => 'integer',
        'caravan_id' => 'integer',
        'entry_order_dte_id' => 'integer',
        'observed_at' => 'date:Y-m-d',
        'entry_order_receipt_sheet_id' => 'integer',
        'recorded_by_user_id' => 'integer',
    ];

    public function animal(): BelongsTo
    {
        return $this->belongsTo(EntryOrderAnimal::class, 'entry_order_animal_id');
    }
}
