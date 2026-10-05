<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntryOrderReceiptSheet extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'entry_order_id',
        'entry_order_dte_id',
        'number',
        'status',
        'weighing_mode',
        'caravan_ids',
        'page_count',
        'processed_pages',
        'issued_by_user_id',
        'printed_at',
        'processed_at',
        'replaced_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'entry_order_id' => 'integer',
        'entry_order_dte_id' => 'integer',
        'number' => 'integer',
        'caravan_ids' => 'array',
        'page_count' => 'integer',
        'processed_pages' => 'array',
        'issued_by_user_id' => 'integer',
        'printed_at' => 'datetime',
        'processed_at' => 'datetime',
        'replaced_at' => 'datetime',
    ];

    public function entryOrder(): BelongsTo
    {
        return $this->belongsTo(EntryOrder::class);
    }

    public function dte(): BelongsTo
    {
        return $this->belongsTo(EntryOrderDte::class, 'entry_order_dte_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }
}
