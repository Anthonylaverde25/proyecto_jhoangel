<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * Something about a batch that needs attention, pointing at the document that explains it.
 *
 * Severity follows the risk: `error` is the life of an animal (an overdue calving), `warning`
 * is work left open (a purchase on its way, a disagreement with a DTE), `info` is context.
 */
final readonly class BatchAlert
{
    public const OVERDUE_BIRTH = 'OVERDUE_BIRTH';
    public const SANITARY_EXPOSURE = 'SANITARY_EXPOSURE';
    public const ENTRY_INCIDENT = 'ENTRY_INCIDENT';
    public const INCOMING_PURCHASE = 'INCOMING_PURCHASE';
    public const ACTIVE_SERVICE = 'ACTIVE_SERVICE';
    public const PENDING_ORDER = 'PENDING_ORDER';
    public const UNWEIGHED = 'UNWEIGHED';

    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFO = 'info';

    private const SEVERITY_RANK = [self::SEVERITY_ERROR => 0, self::SEVERITY_WARNING => 1, self::SEVERITY_INFO => 2];

    /**
     * @param string|null $linkKind entry_order | birth_order | transfer_order | weaning_order | service_order
     *                              | sanitary_exposure_alert
     */
    public function __construct(
        public string $type,
        public string $severity,
        public string $message,
        public ?int $count = null,
        public ?string $linkKind = null,
        public ?int $linkId = null,
        public ?string $linkCode = null,
    ) {
    }

    public function rank(): int
    {
        return self::SEVERITY_RANK[$this->severity] ?? 3;
    }
}
