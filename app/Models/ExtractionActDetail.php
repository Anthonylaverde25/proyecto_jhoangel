<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-42: the data that only an EXTRACTION_ACT has.
 *
 * Keyed by the protocol it belongs to, so the 1:1 is enforced by the primary key rather than by
 * a convention somebody has to remember.
 */
class ExtractionActDetail extends Model
{
    protected $table = 'extraction_act_details';

    protected $primaryKey = 'protocol_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'protocol_id',
        'institution',
        'destination_institution',
        'destination_plan',
        'dispatch_note_number',
        'dispatched_at',
    ];

    protected $casts = [
        'protocol_id' => 'integer',
        'institution' => 'array',
        'destination_institution' => 'array',
        'dispatched_at' => 'date',
    ];

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(DiagnosticProtocol::class, 'protocol_id');
    }
}
