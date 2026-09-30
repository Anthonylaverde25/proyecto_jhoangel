<?php

declare(strict_types=1);

namespace App\Application\UseCases\TransferOrders;

use App\Application\DTOs\Cact01\Cact01SubmissionDTO;
use App\Application\DTOs\TransferOrders\EmitTransferOrderDTO;
use App\Application\DTOs\TransferOrders\RegisterFieldData;
use App\Application\Services\TransferOrderFactory;
use App\Application\Services\TransferOrderSubmissionBuilder;
use App\Application\UseCases\WorkTemplates\ProcessCact01SubmissionUseCase;
use App\Core\Entities\TransferOrderEntity;
use App\Core\Enums\TransferOrderKind;
use App\Core\Exceptions\Cact01ValidationException;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\TransferOrderDomainException;
use App\Core\Interfaces\ITransferOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Registrar transferencia": the animals already moved in the field and the system learns it
 * afterwards. The order is born executed, dated on the day the movement happened.
 *
 * It is created issued and executed through the one path that moves animals, inside a single
 * transaction: if the execution fails, the order never existed. It does not take the source
 * batch — it is closed the moment it exists — so an active order of the batch does not block it;
 * an animal committed to an issued order does.
 */
final class RegisterTransferUseCase
{
    public function __construct(
        private readonly ITransferOrderRepository $repository,
        private readonly TransferOrderFactory $factory,
        private readonly TransferOrderSubmissionBuilder $submissions,
        private readonly ProcessCact01SubmissionUseCase $processCact01
    ) {
    }

    /**
     * @param array<int, RegisterFieldData> $fieldDataByCaravanId weight, teeth, category and notes
     *                                                         measured at the chute, by caravan
     * @return array{order: TransferOrderEntity, warnings: list<array{code: string, message: string}>}
     *
     * @throws TransferOrderDomainException
     * @throws Cact01ValidationException
     * @throws DomainException
     */
    public function __invoke(EmitTransferOrderDTO $dto, array $fieldDataByCaravanId = []): array
    {
        $this->assertDestinationsResolved($dto);

        return DB::transaction(function () use ($dto, $fieldDataByCaravanId): array {
            $order = $this->factory->create(
                $dto,
                TransferOrderKind::REGISTERED,
                'Transferencia registrada después del hecho'
            );

            $result = ($this->processCact01)($this->submissions->fromOrder(
                $order,
                $dto->movementDate,
                Cact01SubmissionDTO::ORIGIN_REGISTRATION,
                $dto->requestedByUserId,
                $fieldDataByCaravanId
            ));

            return [
                'order' => $this->repository->findById((int) $order->getId(), $dto->companyId)
                    ?? throw TransferOrderDomainException::notFound(),
                'warnings' => array_values($result['warnings'] ?? []),
            ];
        });
    }

    /**
     * The chute already happened: there is no "decided later". Every animal has its batch and
     * every batch to be created has its type and management system.
     *
     * @throws TransferOrderDomainException
     */
    private function assertDestinationsResolved(EmitTransferOrderDTO $dto): void
    {
        $unassigned = count(array_filter($dto->animals, fn (array $animal) => $animal['destination_key'] === null));

        if ($unassigned > 0) {
            throw TransferOrderDomainException::domainError(
                "{$unassigned} animal(es) sin lote de destino: en una transferencia registrada cada animal dice adónde fue.",
                'DESTINATION_MISSING'
            );
        }

        foreach ($dto->destinations as $destination) {
            if ($destination['target_batch_id'] === null
                && ($destination['new_batch_type_id'] === null || $destination['is_confined'] === null)) {
                $name = $destination['new_batch_name'] ?? $destination['label'];

                throw TransferOrderDomainException::domainError(
                    "Al lote nuevo '{$name}' le falta el tipo o el sistema de manejo.",
                    'NEW_BATCH_INCOMPLETE'
                );
            }
        }
    }
}
