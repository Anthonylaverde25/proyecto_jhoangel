<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A "Registrar muerte" load with animals to correct, keyed by their position in the request
 * ("caravan_ids.2") so the list marks each one.
 */
final class RegisterDeathException extends FieldValidationException
{
}
