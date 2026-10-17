<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One diagnosis day of a service order. Only the date for now.
 */
class ServiceOrderDiagnosisRound extends Model
{
    use BelongsToCompany;

    protected $table = 'service_order_diagnosis_rounds';

    protected $fillable = [
        'company_id',
        'service_order_id',
        'round_date',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'service_order_id' => 'integer',
        'round_date' => 'date:Y-m-d',
    ];

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(ServiceOrderFemaleDiagnosis::class, 'diagnosis_round_id');
    }
}
