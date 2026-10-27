<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderMale extends Model
{
    use BelongsToCompany;

    protected $table = 'service_order_males';

    protected $fillable = [
        'company_id',
        'service_order_id',
        'male_caravan_id',
        'joined_at',
        'snapshot_scrotal_circumference',
        'sanitary_status_at_entry',
        'service_capacity',
        'status',
        'retired_at',
        'exit_reason',
        'exit_notes',
        'exit_destination_batch_id',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'service_order_id' => 'integer',
        'male_caravan_id' => 'integer',
        'snapshot_scrotal_circumference' => 'float',
        'joined_at' => 'date:Y-m-d',
        'retired_at' => 'datetime',
        'exit_destination_batch_id' => 'integer',
    ];

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function maleCaravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class, 'male_caravan_id');
    }
}
