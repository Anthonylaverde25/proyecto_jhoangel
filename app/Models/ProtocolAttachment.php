<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProtocolAttachment extends Model
{
    use BelongsToCompany;

    protected $table = 'protocol_attachments';

    /** @var string[] */
    protected $fillable = [
        'company_id',
        'diagnostic_protocol_id',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'checksum_sha256',
        'needs_conversion',
        'uploaded_by_user_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'company_id' => 'integer',
        'diagnostic_protocol_id' => 'integer',
        'file_size' => 'integer',
        'needs_conversion' => 'boolean',
        'uploaded_by_user_id' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(DiagnosticProtocol::class, 'diagnostic_protocol_id');
    }
}
