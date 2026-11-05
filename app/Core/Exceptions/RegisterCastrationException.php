<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A castration registration batch with items to correct, keyed by position ("items.0.caravan_id", etc.).
 */
final class RegisterCastrationException extends FieldValidationException
{
}

