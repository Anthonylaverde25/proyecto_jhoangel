<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderBullReplacement extends Model
{
    use BelongsToCompany;

    protected $table = 'service_order_bull_replacements';

    protected $fillable = [
        'company_id',
        'service_order_id',
        'retired_male_caravan_id',
        'replacement_male_caravan_id',
        'replacement_date',
        'reason',
        'destination_batch_id',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'service_order_id' => 'integer',
        'retired_male_caravan_id' => 'integer',
        'replacement_male_caravan_id' => 'integer',
        'destination_batch_id' => 'integer',
        'user_id' => 'integer',
        'replacement_date' => 'datetime',
    ];

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function retiredMaleCaravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class, 'retired_male_caravan_id');
    }

    public function replacementMaleCaravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class, 'replacement_male_caravan_id');
    }

    public function destinationBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'destination_batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
