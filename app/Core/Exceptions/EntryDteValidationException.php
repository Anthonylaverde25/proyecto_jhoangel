<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * Everything wrong with a DTE being loaded, gathered before anything is written: errors of the
 * document itself and errors of each caravan row, so the screen marks every cell at once.
 */
class EntryDteValidationException extends DomainException
{
    /**
     * @param list<array{field: string, code: string, message: string}> $headerErrors
     * @param list<array{row: int, field: string, code: string, message: string}> $rowErrors
     */
    public function __construct(
        string $message,
        private readonly array $headerErrors = [],
        private readonly array $rowErrors = []
    ) {
        parent::__construct($message);
    }

    /**
     * @return list<array{field: string, code: string, message: string}>
     */
    public function getHeaderErrors(): array
    {
        return $this->headerErrors;
    }

    /**
     * @return list<array{row: int, field: string, code: string, message: string}>
     */
    public function getRowErrors(): array
    {
        return $this->rowErrors;
    }
}
