<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaravanMovement extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'caravan_id',
        'company_id',
        'from_batch_id',
        'to_batch_id',
        'provider_id',
        'renspa',
        'from_renspa',
        'type',
        'movement_date',
        'provenance_metadata',
        'observations',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'movement_date' => 'datetime',
        'caravan_id' => 'integer',
        'company_id' => 'integer',
        'from_batch_id' => 'integer',
        'to_batch_id' => 'integer',
        'provider_id' => 'integer',
        'renspa' => 'string',
        'from_renspa' => 'string',
        'provenance_metadata' => 'array',
    ];

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }

    public function fromBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'from_batch_id');
    }

    public function toBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'to_batch_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}

