<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Core\Entities\ItemDocumentEntity;
use App\Core\Interfaces\IItemDocumentRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class FindItemDocumentUseCase
{
    public function __construct(
        private readonly IItemDocumentRepository $documents
    ) {
    }

    public function __invoke(int $id, int $companyId): ItemDocumentEntity
    {
        return $this->documents->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Item document {$id} not found.");
    }
}
