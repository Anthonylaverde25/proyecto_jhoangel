<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderFemale extends Model
{
    use BelongsToCompany;

    protected $table = 'service_order_females';

    protected $fillable = [
        'company_id',
        'service_order_id',
        'female_caravan_id',
        'assigned_male_caravan_id',
        'snapshot_entry_weight',
        'snapshot_body_condition',
        'reproductive_status',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'service_order_id' => 'integer',
        'female_caravan_id' => 'integer',
        'assigned_male_caravan_id' => 'integer',
        'snapshot_entry_weight' => 'float',
        'snapshot_body_condition' => 'float',
    ];

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function femaleCaravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class, 'female_caravan_id');
    }

    public function assignedMaleCaravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class, 'assigned_male_caravan_id');
    }
}
