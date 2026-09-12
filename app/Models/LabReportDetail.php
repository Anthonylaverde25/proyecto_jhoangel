<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-42: the data that only a LAB_REPORT has.
 *
 * `reporting_institution` is NOT NULL in the schema. That is the whole point of the split: the
 * rule "every report names the institution it is filed from" used to live only in a FormRequest,
 * where a seeder or a direct update could walk straight past it.
 */
class LabReportDetail extends Model
{
    protected $table = 'lab_report_details';

    protected $primaryKey = 'protocol_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'protocol_id',
        'reporting_institution',
        'analysing_institution',
        'is_derived',
        'result_date',
    ];

    protected $casts = [
        'protocol_id' => 'integer',
        'reporting_institution' => 'array',
        'analysing_institution' => 'array',
        'is_derived' => 'boolean',
        'result_date' => 'date',
    ];

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(DiagnosticProtocol::class, 'protocol_id');
    }
}
