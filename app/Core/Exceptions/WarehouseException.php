<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A broken rule of the warehouse catalog (delivery D): duplicated code or name, default inactive.
 */
final class WarehouseException extends FieldValidationException
{
}
