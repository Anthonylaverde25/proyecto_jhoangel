<?php

declare(strict_types=1);

namespace App\Http\Rules;

use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\ValueObjects\Cuit;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The CUIT's check digit as a request rule — DELIBERATELY NOT WIRED ANYWHERE.
 *
 * ADR-43 decided the institution's CUIT describes rather than gates: nothing reads it to decide
 * anything, so refusing to store what the professional typed bought nothing and cost the most
 * expensive operation in the app. The warning lives in the interface instead, next to the field.
 *
 * This is kept ready for the day validation is tightened — on request, "primero hagamos funcionar
 * y a futuro validaremos mejor". To turn it on, add `new ValidCuit()` to the `*.cuit` rules of
 * RegisterBullEvaluationSheetRequest, SignExtractionActRequest, RegisterSampleShipmentRequest and
 * both institution blocks of RegisterLabReportRequest — and expect the tests in CuitValidationTest
 * to need flipping back.
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

        try {
            Cuit::fromString($value);
        } catch (VeterinaryDomainException $exception) {
            // The value object already words this well, and one wording beats two that drift.
            $fail($exception->getMessage());
        }
    }
}
