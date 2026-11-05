<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A weight an AI client asked to record that was not written: a blocker, or warnings the user has
 * not accepted yet. Carries a stable code next to the message, which the model repeats to the user.
 */
final class AiWeighingException extends DomainException
{
    public const WARNINGS = 'WEIGHING_WARNINGS';

    public function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** Another company's caravan is not told apart from one that does not exist. */
    public static function caravanNotFound(): self
    {
        return new self('La caravana no existe en esta empresa.', 'CARAVAN_NOT_FOUND');
    }

    public static function externalCaravan(string $label): self
    {
        return new self("{$label} está en un lote externo: no es un animal propio y no se pesa desde aquí.", 'EXTERNAL_CARAVAN');
    }

    public static function weighedAfterExit(string $label, string $exitDate): self
    {
        return new self("{$label} salió el " . self::day($exitDate) . ': no se puede pesar después de esa fecha.', 'WEIGHED_AFTER_EXIT');
    }

    public static function duplicate(string $label, float $weight, string $date): self
    {
        return new self(
            sprintf('%s ya tiene un pesaje de %s kg del %s; no se registró otro.', $label, rtrim(rtrim(number_format($weight, 2, ',', '.'), '0'), ','), self::day($date)),
            'DUPLICATE_WEIGHING'
        );
    }

    /** @param list<string> $warnings */
    public static function unacknowledgedWarnings(array $warnings): self
    {
        return new self(
            'No se registró el pesaje. Mostrale estas advertencias al usuario y reintentá sólo si confirma: ' . implode(' ', $warnings),
            self::WARNINGS
        );
    }

    private static function day(string $isoDate): string
    {
        return (new \DateTimeImmutable($isoDate))->format('d/m/Y');
    }
}
