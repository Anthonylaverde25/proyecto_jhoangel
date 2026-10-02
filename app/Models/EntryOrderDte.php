<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntryOrderDte extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'entry_order_id',
        'dte_number',
        'dte_date',
        'entered_at',
        'head_count',
        'loaded_by_user_id',
        'observations',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'entry_order_id' => 'integer',
        'dte_date' => 'date:Y-m-d',
        'entered_at' => 'date:Y-m-d',
        'head_count' => 'integer',
        'loaded_by_user_id' => 'integer',
    ];

    public function entryOrder(): BelongsTo
    {
        return $this->belongsTo(EntryOrder::class);
    }

    public function loadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'loaded_by_user_id');
    }

    public function animals(): HasMany
    {
        return $this->hasMany(EntryOrderAnimal::class)->orderBy('id');
    }
}
