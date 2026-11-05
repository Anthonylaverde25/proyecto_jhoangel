<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Core\Exceptions\FieldValidationException;

/**
 * Collects every broken rule of a sheet before answering, so the screen marks all the offending
 * cells at once instead of one per attempt.
 */
final class FieldErrorBag
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    public function add(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    public function has(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    /** @return list<string> every message, for a channel that has no fields to mark */
    public function messages(): array
    {
        return array_merge(...array_values($this->errors));
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    /** @param class-string<FieldValidationException> $exception */
    public function throwIfAny(string $exception): void
    {
        if ($this->errors !== []) {
            throw $exception::many($this->errors);
        }
    }
}
