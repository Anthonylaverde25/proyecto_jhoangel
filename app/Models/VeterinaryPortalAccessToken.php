<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A revocable, time boxed grant that lets an external veterinarian or health centre reach
 * the veterinary portal through a URL, without holding a system account.
 */
class VeterinaryPortalAccessToken extends Model
{
    use BelongsToCompany;

    protected $table = 'veterinary_portal_access_tokens';

    /** @var string[] */
    protected $fillable = [
        'company_id',
        'veterinarian_id',
        'batch_id',
        'diagnostic_protocol_id',
        'token_hash',
        'token_prefix',
        'label',
        'expires_at',
        'max_uses',
        'used_count',
        'last_used_at',
        'last_used_ip',
        'revoked_at',
        'revoked_by_user_id',
        'revoke_reason',
        'created_by_user_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'company_id' => 'integer',
        'veterinarian_id' => 'integer',
        'batch_id' => 'integer',
        'diagnostic_protocol_id' => 'integer',
        'expires_at' => 'datetime',
        'max_uses' => 'integer',
        'used_count' => 'integer',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'revoked_by_user_id' => 'integer',
        'created_by_user_id' => 'integer',
    ];

    /** @var string[] */
    protected $hidden = [
        'token_hash',
    ];

    public function veterinarian(): BelongsTo
    {
        return $this->belongsTo(Veterinarian::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * ADR-16: the extraction act this grant is narrowed to, if any.
     */
    public function diagnosticProtocol(): BelongsTo
    {
        return $this->belongsTo(DiagnosticProtocol::class, 'diagnostic_protocol_id');
    }
}
