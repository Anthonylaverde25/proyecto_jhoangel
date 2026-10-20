<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Exceptions\FiscalReaderUnavailableException;
use App\Core\ValueObjects\FiscalIssuerRead;

/**
 * Reads who issued a provider document from its image (the AI agent).
 */
interface IFiscalDocumentReader
{
    /**
     * @throws FiscalReaderUnavailableException
     */
    public function readIssuer(string $contents, string $fileName, string $mimeType, int $companyId): FiscalIssuerRead;
}
