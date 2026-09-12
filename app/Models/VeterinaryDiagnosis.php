<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VeterinaryDiagnosis extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'diagnostic_protocol_id',
        'bull_lab_sample_id',
        'caravan_id',
        'pathogen_id',
        'veterinarian_id',
        'diagnosed_by_user_id',
        'diagnosis_date',
        'status',
        'resolution_date',
        'treatment_notes',
        'source_context',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'diagnostic_protocol_id' => 'integer',
        'bull_lab_sample_id' => 'integer',
        'caravan_id' => 'integer',
        'pathogen_id' => 'integer',
        'veterinarian_id' => 'integer',
        'diagnosed_by_user_id' => 'integer',
        'diagnosis_date' => 'date:Y-m-d',
        'resolution_date' => 'date:Y-m-d',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }

    public function pathogen(): BelongsTo
    {
        return $this->belongsTo(Pathogen::class);
    }

    /**
     * ADR-3: the legally responsible professional from the catalogue, who may have no login.
     */
    public function veterinarian(): BelongsTo
    {
        return $this->belongsTo(Veterinarian::class, 'veterinarian_id');
    }

    /**
     * ADR-3: the system user who typed the record in, kept apart from legal responsibility.
     */
    public function diagnosedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diagnosed_by_user_id');
    }

    public function diagnosticProtocol(): BelongsTo
    {
        return $this->belongsTo(DiagnosticProtocol::class, 'diagnostic_protocol_id');
    }

    public function labSample(): BelongsTo
    {
        return $this->belongsTo(BullLabSample::class, 'bull_lab_sample_id');
    }
}
