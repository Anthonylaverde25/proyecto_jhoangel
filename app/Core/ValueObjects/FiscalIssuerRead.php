<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * What the AI agent read of a provider document's issuer (Delivery F, part 03 §2): the CUIT it
 * chose and where it came from (ARCA QR or vision), the issuer's name and address, and the
 * receiver's CUIT when the document carries one. Plain data: the rules that need the company and
 * its providers are applied by ReadFiscalDocumentImageUseCase.
 */
final class FiscalIssuerRead
{
    public const SOURCE_QR = 'qr';
    public const SOURCE_VISION = 'vision';

    public const PROBLEM_NO_CUIT = 'NO_CUIT';
    public const PROBLEM_ISSUER_BLOCK_UNCERTAIN = 'ISSUER_BLOCK_UNCERTAIN';

    /**
     * @param array<string, mixed>|null $qr
     */
    public function __construct(
        public readonly ?string $issuerCuit,
        public readonly ?string $issuerCuitSource,
        public readonly ?string $discrepancyCuit,
        public readonly ?string $problem,
        public readonly ?string $receiverCuit,
        public readonly ?string $receiverCuitSource,
        public readonly ?string $issuerName,
        public readonly ?string $issuerLegalName,
        public readonly ?string $issuerAddress,
        public readonly ?string $issuerVatCondition,
        public readonly ?array $qr,
        public readonly ?string $visionError,
        public readonly ?string $model,
        public readonly ?int $latencyMs,
    ) {
    }

    /**
     * @param array<string, mixed> $payload The agent's JSON answer.
     */
    public static function fromAgentPayload(array $payload): self
    {
        $digits = static fn (mixed $v): ?string => is_scalar($v) && ($d = preg_replace('/\D/', '', (string) $v)) !== '' ? $d : null;
        $text = static fn (mixed $field): ?string => is_array($field) && is_string($field['value'] ?? null) && trim($field['value']) !== ''
            ? trim($field['value'])
            : null;

        $chosen = (array) ($payload['issuer_cuit'] ?? []);
        $receiver = is_array($payload['receiver_cuit'] ?? null) ? $payload['receiver_cuit'] : [];
        $issuer = is_array($payload['issuer'] ?? null) ? $payload['issuer'] : [];

        return new self(
            issuerCuit: $digits($chosen['value'] ?? null),
            issuerCuitSource: $chosen['source'] ?? null,
            discrepancyCuit: $digits($chosen['discrepancy']['vision_value'] ?? null),
            problem: $chosen['problem'] ?? null,
            receiverCuit: $digits($receiver['value'] ?? null),
            receiverCuitSource: $receiver['source'] ?? null,
            issuerName: $text($issuer['name'] ?? null),
            issuerLegalName: $text($issuer['legal_name'] ?? null),
            issuerAddress: $text($issuer['address'] ?? null),
            issuerVatCondition: $text($issuer['vat_condition'] ?? null),
            qr: is_array($payload['qr'] ?? null) ? $payload['qr'] : null,
            visionError: is_string($payload['vision_error'] ?? null) ? $payload['vision_error'] : null,
            model: is_string($payload['model'] ?? null) ? $payload['model'] : null,
            latencyMs: isset($payload['latency_ms']) ? (int) $payload['latency_ms'] : null,
        );
    }
}
