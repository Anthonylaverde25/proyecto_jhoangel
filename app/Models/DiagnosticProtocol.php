<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DiagnosticProtocol extends Model
{
    use BelongsToCompany;

    protected $table = 'diagnostic_protocols';

    /** @var string[] */
    protected $fillable = [
        'company_id',
        'protocol_number',
        'protocol_type',
        'parent_protocol_id',
        'veterinarian_id',
        'sample_date',
        'result_date',
        'source_channel',
        'status',
        'verification_status',
        'signed_at',
        'signed_by_veterinarian_id',
        'signed_license_number',
        'signed_cuit',
        'signed_billing_cuit',
        'signed_veterinarian_name',
        'observations',
        'created_by_user_id',
        'updated_by_user_id',
        'voided_at',
        'voided_by_user_id',
        'void_reason',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'company_id' => 'integer',
        'veterinarian_id' => 'integer',
        'parent_protocol_id' => 'integer',
        'sample_date' => 'date:Y-m-d',
        'result_date' => 'date:Y-m-d',
        'dispatched_at' => 'date:Y-m-d',
        'signed_at' => 'datetime',
        'signed_by_veterinarian_id' => 'integer',
        'created_by_user_id' => 'integer',
        'updated_by_user_id' => 'integer',
        'voided_at' => 'datetime',
        'voided_by_user_id' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function veterinarian(): BelongsTo
    {
        return $this->belongsTo(Veterinarian::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ProtocolAttachment::class, 'diagnostic_protocol_id');
    }

    public function labSamples(): HasMany
    {
        return $this->hasMany(BullLabSample::class, 'diagnostic_protocol_id');
    }

    /**
     * Tubes created by this extraction act, regardless of whether a laboratory has reported
     * on them yet. `labSamples()` instead holds the tubes this document RESOLVED.
     */
    public function drawnSamples(): HasMany
    {
        return $this->hasMany(BullLabSample::class, 'extraction_act_id');
    }

    /**
     * ADR-42: the per-type data. Exactly one of the two is present on any given row, and which
     * one is decided by `protocol_type`.
     */
    public function extractionActDetail(): HasOne
    {
        return $this->hasOne(ExtractionActDetail::class, 'protocol_id');
    }

    public function labReportDetail(): HasOne
    {
        return $this->hasOne(LabReportDetail::class, 'protocol_id');
    }

    /**
     * ADR-11: the extraction act this laboratory report resolves.
     */
    public function parentProtocol(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_protocol_id');
    }

    public function labReports(): HasMany
    {
        return $this->hasMany(self::class, 'parent_protocol_id');
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(VeterinaryDiagnosis::class, 'diagnostic_protocol_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
