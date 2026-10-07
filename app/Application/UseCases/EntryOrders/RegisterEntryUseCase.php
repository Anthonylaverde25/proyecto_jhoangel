<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Application\DTOs\EntryOrders\LoadDteDTO;
use App\Application\DTOs\EntryOrders\ReceiveDTO;
use App\Application\DTOs\EntryOrders\StoreEntryOrderDTO;
use App\Application\Services\EntryOrderDteService;
use App\Application\Services\EntryOrderFactory;
use App\Application\Services\EntryOrderReceptionService;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Enums\TransferOrderKind;
use App\Core\Exceptions\EntryDteValidationException;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Registrar ingreso": the DTE and the animals are already in hand. The order, its batch, the DTE
 * with the head it declares and a caravan for every animal received on the entry day are created
 * in one transaction: if any row fails, nothing exists. If fewer head than were bought have a DTE,
 * the order stays AWAITING_DTE; if fewer animals than the DTE declares arrived, the rest stay in
 * transit. Either way a closing reason closes it incomplete right away.
 */
final class RegisterEntryUseCase
{
    public function __construct(
        private readonly EntryOrderFactory $factory,
        private readonly EntryOrderDteService $dteService,
        private readonly EntryOrderReceptionService $receptionService,
        private readonly IEntryOrderRepository $repository
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $animals the animals received, one per caravan
     * @return array{order: EntryOrderEntity, warnings: list<array{code: string, message: string, row?: int}>}
     *
     * @throws EntryOrderDomainException
     * @throws EntryDteValidationException
     */
    public function __invoke(StoreEntryOrderDTO $dto, LoadDteDTO $dte, string $enteredAt, array $animals, ?string $closeIncompleteReason = null): array
    {
        return DB::transaction(function () use ($dto, $dte, $enteredAt, $animals, $closeIncompleteReason): array {
            $built = $this->factory->build($dto, TransferOrderKind::REGISTERED, true);
            $order = $built['order'];
            $loaded = $this->dteService->load($order, $dte, $dto->userId);

            $received = $this->receptionService->receive($order, ReceiveDTO::wholeDte($dte->dteNumber, $enteredAt, $animals), $dto->userId);

            $reason = trim((string) $closeIncompleteReason);

            if ($reason !== '' && $order->getStatus()->acceptsReception()) {
                $order->closeIncomplete($reason, $dto->userId);
            }

            $saved = $this->repository->save(
                $order,
                $dto->userId,
                $order->getClosingReason() ?? 'Ingreso registrado con su DTE',
                [...$loaded['metadata'], 'received_at' => $enteredAt, 'received' => $received['metadata']['received']]
            );
            $this->receptionService->settleBatch($saved, $received['metadata']);

            return [
                'order' => $saved,
                'warnings' => [...$built['warnings'], ...$loaded['warnings'], ...$received['warnings']],
            ];
        });
    }
}
