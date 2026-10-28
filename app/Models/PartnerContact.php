<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone we talk to at a partner; at most one per partner is the default (uq_pc_one_default).
 */
class PartnerContact extends Model
{
    protected $fillable = [
        'company_id',
        'partner_id',
        'name',
        'position',
        'phone',
        'email',
        'notes',
        'is_default',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'partner_id' => 'integer',
        'is_default' => 'boolean',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class)->withoutGlobalScopes();
    }
}
