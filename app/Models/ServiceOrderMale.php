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
        'snapshot_scrotal_circumference',
        'service_capacity',
        'status',
        'retired_at',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'service_order_id' => 'integer',
        'male_caravan_id' => 'integer',
        'snapshot_scrotal_circumference' => 'float',
        'retired_at' => 'datetime',
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
