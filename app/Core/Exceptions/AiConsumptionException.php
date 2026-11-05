<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A consumption an AI client asked to record that was not written: its confirmation does not hold, a
 * blocker appeared after the preview, or warnings the user has not accepted yet. Carries a stable code
 * next to the message, which the model repeats to the user.
 */
final class AiConsumptionException extends DomainException
{
    public const CONFIRMATION_INVALID = 'CONFIRMATION_INVALID';
    public const CONFIRMATION_EXPIRED = 'CONFIRMATION_EXPIRED';
    public const BLOCKED = 'CONSUMPTION_BLOCKED';
    public const WARNINGS = 'CONSUMPTION_WARNINGS';

    public function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public static function invalidConfirmation(): self
    {
        return new self('Los datos no coinciden con los que vio el usuario: previsualizá el consumo de nuevo.', self::CONFIRMATION_INVALID);
    }

    public static function expiredConfirmation(): self
    {
        return new self('Pasó demasiado tiempo desde la previsualización: previsualizá el consumo de nuevo.', self::CONFIRMATION_EXPIRED);
    }

    /** @param list<string> $blockers */
    public static function blocked(array $blockers): self
    {
        return new self('No se registró el consumo: ' . implode(' ', $blockers), self::BLOCKED);
    }

    /** @param list<string> $warnings */
    public static function unacknowledgedWarnings(array $warnings): self
    {
        return new self(
            'No se registró el consumo. Mostrale estas advertencias al usuario y reintentá sólo si confirma: ' . implode(' ', $warnings),
            self::WARNINGS
        );
    }
}
