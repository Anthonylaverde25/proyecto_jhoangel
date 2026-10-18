<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A broken rule of a stock operation (plan 4.3) or of the ledger it moves.
 */
final class ItemDocumentException extends FieldValidationException
{
}
