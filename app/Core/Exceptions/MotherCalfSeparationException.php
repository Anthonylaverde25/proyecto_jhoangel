<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A movement would part mothers from their calves at foot and nobody said how they are weaned.
 * Parting them is weaning them: the screen asks for the kind of weaning and sends it again.
 */
final class MotherCalfSeparationException extends FieldValidationException
{
    public function getErrorCode(): string
    {
        return 'MOTHER_CALF_SEPARATION';
    }

    /**
     * @param list<string> $pairs "4521 y su cría 7790"
     */
    public static function forPairs(array $pairs): self
    {
        $count = count($pairs);
        $message = ($count === 1 ? 'Este movimiento separa a ' : "Este movimiento separa {$count} crías de su madre: ")
            . implode('; ', $pairs)
            . '. Separarlas es destetarlas: elegí el tipo de destete.';

        return self::on('weaning_type', $message);
    }
}
