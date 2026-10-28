<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An exit order: animals leaving the company (egreso plan v5). */
class ExitOrder extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'code',
        'kind',
        'reason',
        'status',
        'buyer_partner_id',
        'exit_date',
        'planned_head_count',
        'requested_by_user_id',
        'emitted_at',
        'first_executed_at',
        'closed_at',
        'responsable',
        'observations',
        'closing_reason',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'buyer_partner_id' => 'integer',
        'planned_head_count' => 'integer',
        'requested_by_user_id' => 'integer',
        'exit_date' => 'date:Y-m-d',
        'emitted_at' => 'datetime',
        'first_executed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'buyer_partner_id')->withoutGlobalScopes();
    }

    public function animals(): HasMany
    {
        return $this->hasMany(ExitOrderAnimal::class)->orderBy('id');
    }

    public function dtes(): HasMany
    {
        return $this->hasMany(ExitOrderDte::class)->orderBy('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(ExitOrderHistory::class)->orderBy('id');
    }
}
