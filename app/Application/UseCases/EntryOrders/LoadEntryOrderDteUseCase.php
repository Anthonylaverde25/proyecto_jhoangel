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
 * "Cargar DTE": the document arrived, its caravans enter the order's batch. All or nothing.
 */
final class LoadEntryOrderDteUseCase
{
    public function __construct(
        private readonly EntryOrderDteService $dteService,
        private readonly IEntryOrderRepository $repository
    ) {
    }

    /**
     * @return array{order: EntryOrderEntity, warnings: list<array{code: string, message: string, row?: int}>}
     *
     * @throws EntryOrderDomainException
     * @throws EntryDteValidationException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, LoadDteDTO $dto): array
    {
        return DB::transaction(function () use ($id, $companyId, $userId, $dto): array {
            $order = $this->repository->findById($id, $companyId) ?? throw EntryOrderDomainException::notFound();
            $result = $this->dteService->load($order, $dto, $userId);

            return [
                'order' => $this->repository->save($order, $userId, null, $result['metadata']),
                'warnings' => $result['warnings'],
            ];
        });
    }
}
