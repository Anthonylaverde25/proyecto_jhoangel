<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use Exception;

class ServiceOrderDomainException extends Exception
{
    public static function invalidStateTransition(string $from, string $to): self
    {
        return new self("Invalid state transition from {$from} to {$to}");
    }

    public static function activeOrderConflict(string $type, int $id): self
    {
        return new self("The {$type} with ID {$id} is already assigned to another active service order");
    }

    public static function invalidAnimalSex(int $id, string $expected, string $actual): self
    {
        return new self("Animal with ID {$id} is not a {$expected}. Sex is {$actual}");
    }

    public static function domainError(string $message): self
    {
        return new self($message);
    }
}
