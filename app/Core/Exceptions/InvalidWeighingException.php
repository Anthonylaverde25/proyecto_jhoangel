<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/** A weighing that can never be valid: a weight of zero or less, a calculated shrink without percentage. */
final class InvalidWeighingException extends DomainException
{
}
