<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A verdict given to a female in a diagnosis round. Append-only: a re-check adds a row.
 */
class ServiceOrderFemaleDiagnosis extends Model
{
    use BelongsToCompany;

    protected $table = 'service_order_female_diagnoses';

    protected $fillable = [
        'company_id',
        'service_order_id',
        'diagnosis_round_id',
        'service_order_female_id',
        'female_caravan_id',
        'verdict',
        'gestation_stage',
        'gestation_months',
        'gestation_id',
        'not_diagnosed_reason',
        'not_diagnosed_notes',
        'diagnosed_by_user_id',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'service_order_id' => 'integer',
        'diagnosis_round_id' => 'integer',
        'service_order_female_id' => 'integer',
        'female_caravan_id' => 'integer',
        'gestation_months' => 'float',
        'gestation_id' => 'integer',
        'diagnosed_by_user_id' => 'integer',
    ];

    public function round(): BelongsTo
    {
        return $this->belongsTo(ServiceOrderDiagnosisRound::class, 'diagnosis_round_id');
    }
}
