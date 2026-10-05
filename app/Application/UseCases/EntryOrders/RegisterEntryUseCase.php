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
use App\Core\Enums\EntryOrderStatus;
use App\Core\Enums\TransferOrderKind;
use App\Core\Exceptions\EntryDteValidationException;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Registrar ingreso": the DTE and the animals are already in hand. The order, its batch, the DTE
 * and the caravans are created, and every caravan received on the entry day, in one transaction:
 * if any row fails, nothing exists. If the DTE brings fewer head than were bought the order stays
 * AWAITING_DTE, waiting for another DTE, unless a closing reason is given to close it incomplete
 * right away.
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
     * @param array<string, ?float> $weights entry weight by caravan number, as read on arrival
     * @return array{order: EntryOrderEntity, warnings: list<array{code: string, message: string, row?: int}>}
     *
     * @throws EntryOrderDomainException
     * @throws EntryDteValidationException
     */
    public function __invoke(StoreEntryOrderDTO $dto, LoadDteDTO $dte, string $enteredAt, array $weights = [], ?string $closeIncompleteReason = null): array
    {
        return DB::transaction(function () use ($dto, $dte, $enteredAt, $weights, $closeIncompleteReason): array {
            $built = $this->factory->build($dto, TransferOrderKind::REGISTERED, true);
            $order = $built['order'];
            $loaded = $this->dteService->load($order, $dte, $dto->userId);

            $received = $this->receptionService->receive($order, ReceiveDTO::wholeDte(
                $dte->dteNumber,
                $enteredAt,
                array_map(fn (array $row) => [
                    'identification' => $row['caravana'],
                    'weight' => $weights[mb_strtoupper($row['caravana'])] ?? null,
                ], $dte->animals)
            ), $dto->userId);

            $reason = trim((string) $closeIncompleteReason);

            if ($reason !== '' && $order->getStatus() === EntryOrderStatus::AWAITING_DTE) {
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
