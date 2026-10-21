<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A death that cannot be registered, or a flow that refuses an animal already dead. Carries a
 * stable code next to the message.
 */
final class AnimalDeathException extends DomainException
{
    public function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public static function alreadyDead(string $label, string $date): self
    {
        return new self("{$label} ya está registrado como muerto el {$date}.", 'ALREADY_DEAD');
    }

    public static function beforeBirth(string $label, string $birthDate): self
    {
        return new self("La fecha de muerte de {$label} es anterior a su nacimiento ({$birthDate}).", 'DEATH_BEFORE_BIRTH');
    }

    public static function weighedAfterDeath(string $deathDate): self
    {
        return new self("El animal está registrado como muerto el {$deathDate}: no se pesa después de esa fecha.", 'ALREADY_DEAD');
    }

    public static function future(string $label): self
    {
        return new self("La fecha de muerte de {$label} no puede ser futura.", 'FUTURE_DEATH');
    }
}
