<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

/**
 * The assistant service could not answer (down, timed out or rejected the call). Not a domain
 * rule: the question was fine, the infrastructure behind it was not.
 */
final class AssistantUnavailableException extends RuntimeException
{
    public static function unreachable(): self
    {
        return new self('El asistente no está disponible en este momento. Probá de nuevo en unos minutos.');
    }

    public static function failed(string $detail): self
    {
        return new self($detail !== '' ? $detail : 'El asistente no pudo responder la consulta.');
    }
}
