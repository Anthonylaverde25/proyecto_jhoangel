<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BullLabSample extends Model
{
    use BelongsToCompany;

    protected $table = 'bull_lab_samples';

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'caravan_id',
        'diagnostic_protocol_id',
        'extraction_act_id',
        'sample_shipment_id',
        'extracted_on',
        'veterinarian_id',
        'evaluation_id',
        'sample_type',
        'sample_round',
        'sample_date',
        'tube_number',
        'status',
        'protocol_number',
        'result_date',
        'pathogen_id',
        'notes',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'caravan_id' => 'integer',
        'diagnostic_protocol_id' => 'integer',
        'extraction_act_id' => 'integer',
        'sample_shipment_id' => 'integer',
        'extracted_on' => 'date:Y-m-d',
        'veterinarian_id' => 'integer',
        'evaluation_id' => 'integer',
        'sample_round' => 'integer',
        'sample_date' => 'date:Y-m-d',
        'result_date' => 'date:Y-m-d',
        'pathogen_id' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }

    /**
     * The signed act that created this tube. Immutable once set: it is the chain of custody.
     */
    public function extractionAct(): BelongsTo
    {
        return $this->belongsTo(DiagnosticProtocol::class, 'extraction_act_id');
    }

    /**
     * ADR-30: the shipment that carried this tube. NULL is not a gap — it means the tube is
     * still in the professional's hands, either awaiting dispatch or processed in house.
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(SampleShipment::class, 'sample_shipment_id');
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(BullHealthEvaluation::class, 'evaluation_id');
    }

    public function pathogen(): BelongsTo
    {
        return $this->belongsTo(Pathogen::class);
    }

    public function diagnosticProtocol(): BelongsTo
    {
        return $this->belongsTo(DiagnosticProtocol::class, 'diagnostic_protocol_id');
    }

    public function veterinarian(): BelongsTo
    {
        return $this->belongsTo(Veterinarian::class, 'veterinarian_id');
    }
}
