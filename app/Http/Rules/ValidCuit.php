<?php

declare(strict_types=1);

namespace App\Http\Rules;

use App\Core\ValueObjects\Cuit;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The CUIT's length and check digit as a request rule. Wired only where the CUIT is a key — the
 * provider requests, because it identifies the issuer of every fiscal document.
 *
 * Institutions stay out of it on purpose (ADR-43): there the CUIT describes, and a typo is warned
 * about next to the field, never refused.
 */
class ValidCuit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Optionality is the other rules' business; an absent CUIT is not a malformed one.
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        if (!is_string($value)) {
            $fail('El CUIT debe ser un texto de 11 dígitos.');

            return;
        }

        // The value object words the reason, and one wording beats two that drift.
        $reason = Cuit::fromString($value)->invalidReason();

        if ($reason !== null) {
            $fail($reason);
        }
    }
}
