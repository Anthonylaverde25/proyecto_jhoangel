<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A broken rule tied to the fields that broke it ("lines.3.quantity"), so a sheet can mark every
 * offending cell at once. Rendered as a 422 with those keys under "errors".
 */
abstract class FieldValidationException extends DomainException
{
    /** @param array<string, list<string>> $errors */
    final public function __construct(
        string $message,
        private readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public static function on(string $field, string $message): static
    {
        return new static($message, [$field => [$message]]);
    }

    /** @param array<string, list<string>> $errors */
    public static function many(array $errors): static
    {
        $first = reset($errors);

        return new static($first[0] ?? 'Hay datos para corregir.', $errors);
    }

    /** @return array<string, list<string>> */
    public function getErrors(): array
    {
        return $this->errors === [] ? ['domain' => [$this->getMessage()]] : $this->errors;
    }
}
