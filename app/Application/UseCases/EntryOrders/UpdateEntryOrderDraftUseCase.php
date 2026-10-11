<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Application\DTOs\EntryOrders\StoreEntryOrderDTO;
use App\Application\Services\EntryOrderDestinationValidator;
use App\Application\Services\EntryOrderFactory;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites a draft, and confirms it in the same step when the form says so. The order keeps its
 * code and number: an automatic name is recomposed with the number it already has. Destinations
 * the new troop no longer admits are dropped and told as a warning.
 */
final class UpdateEntryOrderDraftUseCase
{
    public function __construct(
        private readonly EntryOrderFactory $factory,
        private readonly IEntryOrderRepository $repository,
        private readonly EntryOrderDestinationValidator $destinationValidator
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
            $destinations = $dto->toDestinations();
            $this->destinationValidator->assertUsable($destinations->batchIds());

            $dropped = $order->updateDraft($troop, $this->factory->nameFor($dto, $troop, $order->getNumber()), $dto->batchNameMode, $destinations);

            $warnings = $dto->confirm ? $this->factory->confirm($order) : $this->factory->draftNameWarnings($order);

            if ($dropped !== []) {
                $warnings[] = [
                    'code' => 'DESTINATION_RULES_DROPPED',
                    'message' => count($dropped) === 1
                        ? 'Un destino dejó de aplicar con los cambios de la tropa y se quitó: revisá la distribución.'
                        : count($dropped) . ' destinos dejaron de aplicar con los cambios de la tropa y se quitaron: revisá la distribución.',
                ];
            }

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
