<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProtocolAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ADR-6: sanitary evidence has legal value, so reading an attachment requires membership of
 * the owning company, not just possession of a signed URL.
 */
class DiagnosticProtocolPolicy
{
    public function downloadAttachment(User $user, ProtocolAttachment $attachment): bool
    {
        return DB::table('company_user')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('company_id', $attachment->company_id)
            ->exists();
    }

    public function void(User $user, int $companyId): bool
    {
        return DB::table('company_user')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('company_id', $companyId)
            ->exists();
    }
}
