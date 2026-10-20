<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Core\Entities\ProviderEntity;
use App\Core\ValueObjects\FiscalIssuerRead;

/**
 * Who issued the document, as far as the company's portfolio is concerned (Delivery F, part 01).
 */
final readonly class ProviderRecognition
{
    public const MATCHED = 'MATCHED';
    public const ROLE_MISSING = 'ROLE_MISSING';
    public const NEW = 'NEW';
    public const UNREADABLE = 'UNREADABLE';

    public const REASON_NO_CUIT = 'NO_CUIT';
    public const REASON_ISSUER_BLOCK_UNCERTAIN = 'ISSUER_BLOCK_UNCERTAIN';
    public const REASON_INVALID_CHECK_DIGIT = 'INVALID_CHECK_DIGIT';
    public const REASON_SAME_AS_RECEIVER = 'SAME_AS_RECEIVER';
    public const REASON_ISSUED_BY_OWN_COMPANY = 'ISSUED_BY_OWN_COMPANY';
    public const REASON_ISSUER_IS_OWN_COMPANY = 'ISSUER_IS_OWN_COMPANY';

    public function __construct(
        public string $status,
        public ?string $reason,
        public ?ProviderEntity $provider,
        public FiscalIssuerRead $read,
        public ?string $companyCuit,
        /** ISSUER_IS_OWN_COMPANY: the other CUIT read, probably the real issuer. */
        public ?string $suggestedCuit = null,
        /** R4: the document names a receiver that is not the company. */
        public bool $receiverMismatch = false,
    ) {
    }

    public function companyCuitMissing(): bool
    {
        return $this->companyCuit === null;
    }
}
