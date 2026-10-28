<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * An animal that cannot leave the company on that date (E1, E5), or one a flow refuses because it
 * already left. Carries a stable code next to the message.
 */
final class CaravanExitException extends DomainException
{
    public function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public static function dead(string $label, string $date): self
    {
        return new self("{$label} está registrado como muerto el {$date}: no sale por un egreso.", 'DEAD');
    }

    public static function beforeBirth(string $label, string $birthDate): self
    {
        return new self("La fecha de salida de {$label} es anterior a su nacimiento ({$birthDate}).", 'EXIT_BEFORE_BIRTH');
    }

    public static function beforeEntry(string $label, string $entryDate): self
    {
        return new self("La fecha de salida de {$label} es anterior a su ingreso ({$entryDate}).", 'EXIT_BEFORE_ENTRY');
    }

    public static function future(string $label): self
    {
        return new self("La fecha de salida de {$label} no puede ser futura.", 'FUTURE_EXIT');
    }
}
