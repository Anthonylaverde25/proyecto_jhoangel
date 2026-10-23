<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A broken rule when issuing an AI client connection by hand (rendered as a 422).
 */
final class McpConnectionException extends FieldValidationException
{
    public static function invalidTtl(int $maxDays): self
    {
        return self::on('ttl_days', "La vigencia debe estar entre 1 y {$maxDays} días.");
    }

    public static function tooManyActive(int $max): self
    {
        return self::on(
            'name',
            "Ya tenés {$max} conexiones de IA activas en esta empresa. Revocá alguna antes de crear otra."
        );
    }

    public static function companyRequired(): self
    {
        return self::on('company_id', 'Elegí la empresa a la que se conectará el asistente.');
    }
}
