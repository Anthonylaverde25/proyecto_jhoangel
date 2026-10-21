<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use App\Core\Entities\CaravanEntity;

/**
 * A calf born in the system may wait for its tag. Every flow that works by tag (transfer, weaning,
 * weighing, exit) stops here instead of handling an animal it cannot identify on the field.
 */
final class UntaggedAnimalException extends DomainException
{
    public function __construct(string $message, private readonly string $errorCode = 'UNTAGGED_ANIMAL')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public static function for(CaravanEntity $animal): self
    {
        return new self("La " . lcfirst($animal->label()) . " todavía no tiene caravana: caravaneala antes de seguir.");
    }

    public static function alreadyTagged(CaravanEntity $animal): self
    {
        return new self("El animal ya tiene la caravana {$animal->label()}.", 'ALREADY_TAGGED');
    }
}
