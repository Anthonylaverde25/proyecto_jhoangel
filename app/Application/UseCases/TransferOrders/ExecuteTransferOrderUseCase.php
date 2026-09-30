<?php

declare(strict_types=1);

namespace App\Application\UseCases\TransferOrders;

use App\Application\DTOs\Cact01\Cact01SubmissionDTO;
use App\Application\DTOs\TransferOrders\RegisterFieldData;
use App\Application\Services\TransferOrderSubmissionBuilder;
use App\Application\UseCases\WorkTemplates\ProcessCact01SubmissionUseCase;
use App\Core\Exceptions\Cact01ValidationException;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\TransferOrderDomainException;
use App\Core\Interfaces\ITransferOrderRepository;

/**
 * "Transferir" on the screen, with an order already issued: executes the order itself.
 *
 * The submission is built from the ORDER, not from whatever the screen shows, so what gets
 * moved is exactly what was committed. It goes through the CACT-01 processing like a scanned
 * sheet would — the one place that moves animals — marked as coming from the screen.
 *
 * Executing asks more than ordering: every pending animal needs a batch, and every batch to be
 * created needs its type and management system. An order that left those for the chute is
 * executed by scanning its sheet.
 */
final class ExecuteTransferOrderUseCase
{
    public function __construct(
        private readonly ITransferOrderRepository $repository,
        private readonly ProcessCact01SubmissionUseCase $processCact01,
        private readonly TransferOrderSubmissionBuilder $submissions
    ) {
    }

    /**
     * @param ?string $movementDate when the movement happened (Y-m-d); null is today
     * @param array<int, RegisterFieldData> $fieldDataByCaravanId the C/S declared for each animal
     *        at execution time. It replaces what the order declared for that animal, and it is how
     *        an order whose category was left for the chute gets executed without its sheet.
     *
     * @return array<string, mixed> the CACT-01 result
     *
     * @throws Cact01ValidationException
     * @throws DomainException
     */
    public function __invoke(
        int $id,
        int $companyId,
        ?int $userId,
        ?string $movementDate = null,
        array $fieldDataByCaravanId = []
    ): array {
        $order = $this->repository->findById($id, $companyId) ?? throw TransferOrderDomainException::notFound();

        if (!$order->getStatus()->isOpen()) {
            throw TransferOrderDomainException::domainError(
                $order->getStatus()->isEditable()
                    ? "La orden {$order->getCode()} es un borrador: emitila antes de ejecutarla."
                    : "La orden {$order->getCode()} está {$order->getStatus()->label()}: no admite más movimientos.",
                'TRANSFER_ORDER_NOT_EXECUTABLE'
            );
        }

        // The day the animals moved, which the order's planned date does not decide. Today unless
        // the screen says otherwise; a day before the order existed is rejected by the use case.
        $date = $movementDate !== null
            ? substr($movementDate, 0, 10)
            : (new \DateTimeImmutable('today'))->format('Y-m-d');

        return ($this->processCact01)(
            $this->submissions->fromOrder($order, $date, Cact01SubmissionDTO::ORIGIN_SCREEN, $userId, $fieldDataByCaravanId)
        );
    }
}
