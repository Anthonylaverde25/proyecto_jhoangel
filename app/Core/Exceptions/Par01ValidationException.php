<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * Carries every problem found on a PAR-01 sheet at once, so the operator can repair all the pages in
 * a single pass instead of discovering errors one resubmission at a time.
 */
final class Par01ValidationException extends DomainException
{
    /**
     * @param array<int, array{field: string, code: string, message: string}> $headerErrors
     * @param array<int, array{row_index: int, caravana_madre: string, errors: array<int, array{code: string, message: string, field?: string}>}> $rowErrors
     */
    public function __construct(
        private readonly array $headerErrors,
        private readonly array $rowErrors
    ) {
        parent::__construct('La planilla PAR-01 tiene errores. Corregilos antes de confirmar.');
    }

    /**
     * @return array<int, array{field: string, code: string, message: string}>
     */
    public function getHeaderErrors(): array
    {
        return $this->headerErrors;
    }

    /**
     * @return array<int, array{row_index: int, caravana_madre: string, errors: array<int, array{code: string, message: string, field?: string}>}>
     */
    public function getRowErrors(): array
    {
        return $this->rowErrors;
    }
}
