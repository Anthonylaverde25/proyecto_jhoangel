<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * How the sanitary evidence reached the system. This is not decorative: a hand transcribed
 * report defaults to UNVERIFIED, while a portal submission is signed by the professional.
 */
enum DiagnosticSourceChannel: string
{
    case PORTAL_VET = 'PORTAL_VET';
    case OWNER_DIGITIZED = 'OWNER_DIGITIZED';

    public function requiresAttachment(): bool
    {
        return $this === self::OWNER_DIGITIZED;
    }

    public function defaultVerificationStatus(): ProtocolVerificationStatus
    {
        return $this === self::PORTAL_VET
            ? ProtocolVerificationStatus::VERIFIED
            : ProtocolVerificationStatus::UNVERIFIED;
    }
}
