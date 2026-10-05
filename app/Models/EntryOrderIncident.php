<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntryOrderIncident extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'entry_order_id',
        'entry_order_dte_id',
        'type',
        'detail',
        'metadata',
        'status',
        'resolution',
        'resolved_by_user_id',
        'resolved_at',
        'raised_by_user_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'entry_order_id' => 'integer',
        'entry_order_dte_id' => 'integer',
        'metadata' => 'array',
        'resolved_by_user_id' => 'integer',
        'resolved_at' => 'datetime',
        'raised_by_user_id' => 'integer',
    ];

    public function entryOrder(): BelongsTo
    {
        return $this->belongsTo(EntryOrder::class);
    }

    public function dte(): BelongsTo
    {
        return $this->belongsTo(EntryOrderDte::class, 'entry_order_dte_id');
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
