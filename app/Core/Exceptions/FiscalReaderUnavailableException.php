<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

/**
 * The AI agent could not read the document (down, timed out or rejected it). Not a domain rule:
 * the document may be fine; manual entry keeps working.
 */
final class FiscalReaderUnavailableException extends RuntimeException
{
    public static function unreachable(): self
    {
        return new self('No se pudo leer la imagen: el agente de IA no está disponible. Podés cargar el comprobante a mano.');
    }

    public static function failed(string $detail): self
    {
        return new self('No se pudo leer la imagen: ' . ($detail !== '' ? $detail : 'el agente de IA no respondió.'));
    }
}
