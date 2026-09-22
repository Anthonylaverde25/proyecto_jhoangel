<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * Carries every problem found on a DEST-01 sheet at once, so the operator can repair all the
 * pages in a single pass instead of discovering errors one resubmission at a time.
 */
final class Dest01ValidationException extends DomainException
{
    /**
     * @param array<int, array{field: string, code: string, message: string}> $headerErrors
     * @param array<int, array{row_index: int, caravana: string, errors: array<int, array{code: string, message: string}>}> $rowErrors
     */
    public function __construct(
        private readonly array $headerErrors,
        private readonly array $rowErrors
    ) {
        parent::__construct('La planilla DEST-01 tiene errores. Corríjalos antes de confirmar.');
    }

    /**
     * @return array<int, array{field: string, code: string, message: string}>
     */
    public function getHeaderErrors(): array
    {
        return $this->headerErrors;
    }

    /**
     * @return array<int, array{row_index: int, caravana: string, errors: array<int, array{code: string, message: string}>}>
     */
    public function getRowErrors(): array
    {
        return $this->rowErrors;
    }
}
