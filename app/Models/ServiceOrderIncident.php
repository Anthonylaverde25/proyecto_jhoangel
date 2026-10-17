<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A non-blocking fact of a service order to settle later (OPEN -> RESOLVED with a note).
 */
class ServiceOrderIncident extends Model
{
    use BelongsToCompany;

    protected $table = 'service_order_incidents';

    protected $fillable = [
        'company_id',
        'service_order_id',
        'female_caravan_id',
        'type',
        'detail',
        'metadata',
        'status',
        'resolution',
        'resolved_by_user_id',
        'resolved_at',
        'raised_by_user_id',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'service_order_id' => 'integer',
        'female_caravan_id' => 'integer',
        'metadata' => 'array',
        'resolved_by_user_id' => 'integer',
        'resolved_at' => 'datetime',
        'raised_by_user_id' => 'integer',
    ];

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function femaleCaravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class, 'female_caravan_id');
    }
}
