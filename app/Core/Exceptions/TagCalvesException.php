<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A load of "Caravanear crías" with rows to correct: tag repeated or in use, calf already tagged,
 * date out of range. Keyed by row ("items.3.identification") so the sheet marks every cell at once.
 */
final class TagCalvesException extends FieldValidationException
{
}
