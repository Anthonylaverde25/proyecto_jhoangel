<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Application\DTOs\EntryOrders\StoreEntryOrderDTO;
use App\Application\Services\EntryOrderFactory;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites a draft, and confirms it in the same step when the form says so. The order keeps its
 * code and number: an automatic name is recomposed with the number it already has.
 */
final class UpdateEntryOrderDraftUseCase
{
    public function __construct(
        private readonly EntryOrderFactory $factory,
        private readonly IEntryOrderRepository $repository
    ) {
    }

    /**
     * @return array{order: EntryOrderEntity, warnings: list<array{code: string, message: string}>}
     *
     * @throws EntryOrderDomainException
     */
    public function __invoke(int $id, StoreEntryOrderDTO $dto): array
    {
        return DB::transaction(function () use ($id, $dto): array {
            $order = $this->repository->findById($id, $dto->companyId) ?? throw EntryOrderDomainException::notFound();
            $troop = $this->factory->troopOf($dto);

            $order->updateDraft($troop, $this->factory->nameFor($dto, $troop, $order->getNumber()), $dto->batchNameMode);

            $warnings = $dto->confirm ? $this->factory->confirm($order) : $this->factory->draftNameWarnings($order);

            $saved = $this->repository->save(
                $order,
                $dto->userId,
                $dto->confirm ? 'Compra confirmada: en espera de DTE' : null,
                $dto->confirm ? null : ['action' => 'draft_updated']
            );

            return ['order' => $saved, 'warnings' => $warnings];
        });
    }
}
