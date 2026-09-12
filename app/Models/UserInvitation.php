<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-33: the professional's credential is born here, and the producer never sees it. Only the
 * token's hash is stored.
 */
class UserInvitation extends Model
{
    use BelongsToCompany;

    protected $table = 'user_invitations';

    /** @var string[] */
    protected $fillable = [
        'company_id',
        'veterinarian_id',
        'email',
        'token_hash',
        'expires_at',
        'accepted_at',
        'accepted_user_id',
        'invited_by_user_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'company_id' => 'integer',
        'veterinarian_id' => 'integer',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'accepted_user_id' => 'integer',
        'invited_by_user_id' => 'integer',
    ];

    public function veterinarian(): BelongsTo
    {
        return $this->belongsTo(Veterinarian::class);
    }

    /** Usable exactly once, and only while it lasts. */
    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
