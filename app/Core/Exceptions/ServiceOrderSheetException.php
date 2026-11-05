<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/** A broken rule of the service order sheet, tied to the batch or bull cell that broke it. */
final class ServiceOrderSheetException extends FieldValidationException
{
}
