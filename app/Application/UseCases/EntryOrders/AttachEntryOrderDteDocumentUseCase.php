<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Application\DTOs\EntryOrders\LoadDteDTO;
use App\Application\Services\EntryOrderDteService;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Exceptions\EntryDteValidationException;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Cargar DTE" on head that moved without it: the paper arrived, also days after the order closed.
 * All or nothing.
 */
final class AttachEntryOrderDteDocumentUseCase
{
    public function __construct(
        private readonly EntryOrderDteService $dteService,
        private readonly IEntryOrderRepository $repository
    ) {
    }

    /**
     * @return array{order: EntryOrderEntity, warnings: list<array{code: string, message: string}>}
     *
     * @throws EntryOrderDomainException
     * @throws EntryDteValidationException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, int $dteId, LoadDteDTO $dto): array
    {
        return DB::transaction(function () use ($id, $companyId, $userId, $dteId, $dto): array {
            $order = $this->repository->findById($id, $companyId) ?? throw EntryOrderDomainException::notFound();
            $result = $this->dteService->attachDocument($order, $dteId, $dto, $userId);

            return [
                'order' => $this->repository->save($order, $userId, null, $result['metadata']),
                'warnings' => $result['warnings'],
            ];
        });
    }
}
