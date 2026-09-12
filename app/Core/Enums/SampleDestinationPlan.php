<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * ADR-39 / ADR-40: what the professional INTENDS to do with the tubes, declared at the chute.
 *
 * This is a plan, not a fact. Nothing validates against it: the tubes may end up processed in
 * house after the cold chain broke, or derived after SENASA designated a laboratory that had not
 * been designated when the samples were drawn. A plan that changed does not make the act false —
 * the intention was real when it was declared — which is why it is a "previsto" and why the fact
 * of the derivation lives on the lab report, where the professional attests to it (ADR-31 rev.).
 *
 * Two consumers only: prefilling the lab report, and letting the producer see what is about to
 * leave before it leaves.
 */
enum SampleDestinationPlan: string
{
    case IN_SITU = 'IN_SITU';
    case TO_BE_DERIVED = 'TO_BE_DERIVED';
    case UNDECIDED = 'UNDECIDED';

    /**
     * The default for an act nobody declared anything on, which includes every act that predates
     * this field. Saying "undecided" is true; inventing an intention would not be.
     */
    public static function default(): self
    {
        return self::UNDECIDED;
    }

    public static function fromNullable(?string $value): self
    {
        if ($value === null || trim($value) === '') {
            return self::default();
        }

        return self::tryFrom(trim($value)) ?? self::default();
    }

    /**
     * Whether the lab report dialog should open with "Es derivado" already ticked. A suggestion
     * the professional may override — never a rule (ADR-40).
     */
    public function suggestsDerivation(): bool
    {
        return $this === self::TO_BE_DERIVED;
    }

    public function label(): string
    {
        return match ($this) {
            self::IN_SITU => 'Se procesa en la institución del acta',
            self::TO_BE_DERIVED => 'Se enviará a otro laboratorio o centro',
            self::UNDECIDED => 'Todavía no está definido',
        };
    }
}
