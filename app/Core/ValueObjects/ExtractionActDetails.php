<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\SampleDestinationPlan;
use JsonSerializable;

/**
 * ADR-39: what accompanies an extraction act and nothing else.
 *
 * The institution is optional on purpose — a field sampling may have no centre behind it — and
 * so is knowing the destination, which is why UNDECIDED exists. Both are frozen by the
 * signature, like every other thing the act attests to (ADR-38).
 *
 * ADR-39 rev.: `destinationInstitution` is the one exception, and deliberately so. It is declared
 * when the box is dispatched, which can only happen after the act is signed, so it never was part
 * of what the professional put their name to. It has the standing of the shipment that wrote it.
 */
final class ExtractionActDetails implements JsonSerializable
{
    public function __construct(
        private readonly ?InstitutionMeta $institution = null,
        private readonly SampleDestinationPlan $destinationPlan = SampleDestinationPlan::UNDECIDED,
        private readonly ?string $dispatchNoteNumber = null,
        private readonly ?string $dispatchedAt = null,
        // ADR-39 rev.: a dónde se declaró que iban los tubos. Se escribe al despachar, y por
        // eso es lo único acá que la firma NO congela: el despacho siempre ocurre después.
        private readonly ?InstitutionMeta $destinationInstitution = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            institution: InstitutionMeta::fromNullableArray(
                is_array($data['institution'] ?? null) ? $data['institution'] : null
            ),
            destinationPlan: SampleDestinationPlan::fromNullable(
                isset($data['destination_plan']) ? (string) $data['destination_plan'] : null
            ),
            dispatchNoteNumber: isset($data['dispatch_note_number']) && $data['dispatch_note_number'] !== null
                ? (string) $data['dispatch_note_number']
                : null,
            dispatchedAt: isset($data['dispatched_at']) && $data['dispatched_at'] !== null
                ? (string) $data['dispatched_at']
                : null,
            destinationInstitution: InstitutionMeta::fromNullableArray(
                is_array($data['destination_institution'] ?? null) ? $data['destination_institution'] : null
            )
        );
    }

    /**
     * An act with no detail row yet — every act drawn before ADR-39 — reads as "nothing declared".
     */
    public static function undeclared(): self
    {
        return new self();
    }

    public function getInstitution(): ?InstitutionMeta
    {
        return $this->institution;
    }

    public function getDestinationPlan(): SampleDestinationPlan
    {
        return $this->destinationPlan;
    }

    public function getDispatchNoteNumber(): ?string
    {
        return $this->dispatchNoteNumber;
    }

    public function getDispatchedAt(): ?string
    {
        return $this->dispatchedAt;
    }

    /**
     * ADR-39 rev.: la institución a la que el profesional declaró haber despachado los tubos.
     *
     * Null mientras no se despachó nada, y en toda acta que no se deriva. No es el centro del
     * acta: aquél es el remitente (getInstitution()), éste el destinatario.
     */
    public function getDestinationInstitution(): ?InstitutionMeta
    {
        return $this->destinationInstitution;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'institution' => $this->institution?->jsonSerialize(),
            'destination_plan' => $this->destinationPlan->value,
            'destination_plan_label' => $this->destinationPlan->label(),
            'dispatch_note_number' => $this->dispatchNoteNumber,
            'dispatched_at' => $this->dispatchedAt,
            'destination_institution' => $this->destinationInstitution?->jsonSerialize(),
        ];
    }
}
