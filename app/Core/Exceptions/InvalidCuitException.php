<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A CUIT that cannot be a key: wrong length or a check digit that does not close. Raised only
 * where the CUIT identifies someone (providers); institutions only warn (ADR-43).
 */
final class InvalidCuitException extends FieldValidationException
{
}
