<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Application\DTOs\EntryOrders\LoadDteDTO;
use App\Application\DTOs\EntryOrders\StoreEntryOrderDTO;
use App\Application\Services\EntryOrderDteService;
use App\Application\Services\EntryOrderFactory;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Enums\EntryOrderStatus;
use App\Core\Enums\TransferOrderKind;
use App\Core\Exceptions\EntryDteValidationException;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Registrar ingreso": the DTE is already in hand. The order, its batch, the DTE and the caravans
 * are created in one transaction: if any row fails, nothing exists. If the DTE brings fewer head
 * than were bought the order stays PARTIAL, waiting for another DTE, unless a closing reason is
 * given to close it incomplete right away.
 */
final class RegisterEntryUseCase
{
    public function __construct(
        private readonly EntryOrderFactory $factory,
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
    public function __invoke(StoreEntryOrderDTO $dto, LoadDteDTO $dte, ?string $closeIncompleteReason = null): array
    {
        return DB::transaction(function () use ($dto, $dte, $closeIncompleteReason): array {
            $built = $this->factory->build($dto, TransferOrderKind::REGISTERED, true);
            $order = $built['order'];
            $result = $this->dteService->load($order, $dte, $dto->userId);
            $reason = trim((string) $closeIncompleteReason);

            if ($reason !== '' && $order->getStatus() === EntryOrderStatus::PARTIAL) {
                $order->closeIncomplete($reason);
            }

            return [
                'order' => $this->repository->save($order, $dto->userId, $order->getClosingReason() ?? 'Ingreso registrado con su DTE', $result['metadata']),
                'warnings' => [...$built['warnings'], ...$result['warnings']],
            ];
        });
    }
}
