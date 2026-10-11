<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where the animals of one category line of an entry order go: the whole line (ALL) or one sex.
 */
class EntryOrderDestination extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'entry_order_id',
        'entry_order_category_id',
        'sex_scope',
        'destination_batch_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'entry_order_id' => 'integer',
        'entry_order_category_id' => 'integer',
        'destination_batch_id' => 'integer',
    ];

    public function categoryLine(): BelongsTo
    {
        return $this->belongsTo(EntryOrderCategory::class, 'entry_order_category_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'destination_batch_id');
    }
}
