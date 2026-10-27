<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A load of paternity confirmations with rows to correct: calf not pending, sire not a male, the
 * mother as sire. Keyed by row ("confirmations.3.father_id") so the sheet marks every cell at once.
 */
final class PaternityException extends FieldValidationException
{
}
