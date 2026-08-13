<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaravanLineage extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'caravan_lineage';

    /**
     * @var string[]
     */
    protected $fillable = [
        'caravan_id',
        'mother_id',
        'father_id',
        'gestation_id',
        'birth_date',
        'is_nursing',
        'sire_assigned_at',
        'sire_identification_method',
        'sire_notes',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'caravan_id'                 => 'integer',
        'mother_id'                  => 'integer',
        'father_id'                  => 'integer',
        'gestation_id'               => 'integer',
        'birth_date'                 => 'date:Y-m-d',
        'is_nursing'                 => 'boolean',
        'sire_assigned_at'           => 'datetime',
        'sire_identification_method' => 'string',
        'sire_notes'                 => 'string',
    ];

    /**
     * Get the caravan offspring.
     *
     * @return BelongsTo
     */
    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class, 'caravan_id');
    }

    /**
     * Get the mother caravan.
     *
     * @return BelongsTo
     */
    public function mother(): BelongsTo
    {
        return $this->belongsTo(Caravan::class, 'mother_id');
    }

    /**
     * Get the father caravan (sire).
     *
     * @return BelongsTo
     */
    public function father(): BelongsTo
    {
        return $this->belongsTo(Caravan::class, 'father_id');
    }

    /**
     * Get the gestation associated with this lineage.
     *
     * @return BelongsTo
     */
    public function gestation(): BelongsTo
    {
        return $this->belongsTo(CaravanGestation::class, 'gestation_id');
    }
}
