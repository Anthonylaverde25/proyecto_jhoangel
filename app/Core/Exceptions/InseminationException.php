<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/** A broken rule of an AI order's inseminations (semen S-B), tied to the sheet cells that broke it. */
final class InseminationException extends FieldValidationException
{
}
