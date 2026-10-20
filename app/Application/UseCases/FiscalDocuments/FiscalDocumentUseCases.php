<?php

declare(strict_types=1);

namespace App\Application\UseCases\FiscalDocuments;

final class FiscalDocumentUseCases
{
    public function __construct(
        public readonly SearchFiscalDocumentsUseCase $search,
        public readonly FindFiscalDocumentUseCase $find,
        public readonly RegisterFiscalDocumentUseCase $register,
        public readonly VoidFiscalDocumentUseCase $void,
        public readonly ReadFiscalDocumentImageUseCase $readImage,
    ) {
    }
}
