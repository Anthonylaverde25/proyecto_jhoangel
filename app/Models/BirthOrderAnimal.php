<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BirthOrderAnimal extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'birth_order_id',
        'mother_caravan_id',
        'gestation_id',
        'source_batch_id',
        'unplanned',
        'status',
        'outcome',
        'event_date',
        'calf_caravan_id',
        'calf_batch_id',
        'executed_at',
        'observations',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'birth_order_id' => 'integer',
        'mother_caravan_id' => 'integer',
        'gestation_id' => 'integer',
        'source_batch_id' => 'integer',
        'unplanned' => 'boolean',
        'event_date' => 'date:Y-m-d',
        'calf_caravan_id' => 'integer',
        'calf_batch_id' => 'integer',
        'executed_at' => 'datetime',
    ];

    public function birthOrder(): BelongsTo
    {
        return $this->belongsTo(BirthOrder::class);
    }

    public function mother(): BelongsTo
    {
        return $this->belongsTo(Caravan::class, 'mother_caravan_id');
    }

    public function gestation(): BelongsTo
    {
        return $this->belongsTo(CaravanGestation::class, 'gestation_id');
    }

    public function sourceBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'source_batch_id');
    }

    public function calf(): BelongsTo
    {
        return $this->belongsTo(Caravan::class, 'calf_caravan_id');
    }

    public function calfBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'calf_batch_id');
    }
}
