<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeaningOrders;

use App\Application\DTOs\Dest01\Dest01SubmissionDTO;
use App\Application\DTOs\WeaningOrders\EmitWeaningOrderDTO;
use App\Application\DTOs\WeaningOrders\WeaningFieldData;
use App\Application\Services\WeaningOrderFactory;
use App\Application\Services\WeaningOrderSubmissionBuilder;
use App\Application\UseCases\WorkTemplates\ProcessDest01SubmissionUseCase;
use App\Core\Entities\WeaningOrderEntity;
use App\Core\Enums\TransferOrderCategoryMode;
use App\Core\Enums\TransferOrderKind;
use App\Core\Exceptions\Dest01ValidationException;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\WeaningOrderDomainException;
use App\Core\Interfaces\IWeaningOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Registrar destete": the calves were already weaned and the system learns it afterwards. The
 * order is born executed, dated on the day the weaning happened.
 *
 * It is created issued and executed through the one path that weans, inside a single transaction:
 * if the weaning fails, the order never existed. A calf committed to another open order refuses it.
 */
final class RegisterWeaningUseCase
{
    public function __construct(
        private readonly IWeaningOrderRepository $repository,
        private readonly WeaningOrderFactory $factory,
        private readonly WeaningOrderSubmissionBuilder $submissions,
        private readonly ProcessDest01SubmissionUseCase $processDest01
    ) {
    }

    /**
     * @param array<int, WeaningFieldData> $fieldDataByCaravanId weight and notes per calf
     * @return array{order: WeaningOrderEntity, warnings: list<array{code: string, message: string}>}
     *
     * @throws WeaningOrderDomainException
     * @throws Dest01ValidationException
     * @throws DomainException
     */
    public function __invoke(EmitWeaningOrderDTO $dto, array $fieldDataByCaravanId = []): array
    {
        $this->assertResolved($dto);

        return DB::transaction(function () use ($dto, $fieldDataByCaravanId): array {
            $order = $this->factory->create($dto, TransferOrderKind::REGISTERED, 'Destete registrado después del hecho');

            $result = ($this->processDest01)($this->submissions->fromOrder(
                $order,
                $dto->weaningDate,
                Dest01SubmissionDTO::ORIGIN_REGISTRATION,
                $dto->requestedByUserId,
                $fieldDataByCaravanId
            ));

            return [
                'order' => $this->repository->findById((int) $order->getId(), $dto->companyId)
                    ?? throw WeaningOrderDomainException::notFound(),
                'warnings' => array_values($result['warnings'] ?? []),
            ];
        });
    }

    /**
     * The chute already happened: there is no "decided later". Every calf has its weaning batch,
     * every batch to be created its management system, and the category is kept or declared.
     *
     * @throws WeaningOrderDomainException
     */
    private function assertResolved(EmitWeaningOrderDTO $dto): void
    {
        if ($dto->categoryMode === TransferOrderCategoryMode::AT_CHUTE) {
            throw WeaningOrderDomainException::domainError(
                'En un destete registrado la categoría ya se decidió: declarala por cría o indicá que no cambia.',
                'CATEGORY_MODE_NOT_ALLOWED'
            );
        }

        if ($dto->destinationMode === WeaningOrderEntity::MODE_PER_ANIMAL) {
            $unassigned = count(array_filter($dto->animals, fn (array $animal) => $animal['destination_key'] === null));

            if ($unassigned > 0) {
                throw WeaningOrderDomainException::domainError(
                    "{$unassigned} cría(s) sin lote de destete: en un destete registrado cada cría dice adónde fue.",
                    'DESTINATION_MISSING'
                );
            }
        }

        foreach ($dto->destinations as $destination) {
            if ($destination['target_batch_id'] === null && $destination['is_confined'] === null) {
                $name = $destination['new_batch_name'] ?? $destination['label'];

                throw WeaningOrderDomainException::domainError(
                    "Al lote de destete nuevo '{$name}' le falta el sistema de manejo.",
                    'NEW_BATCH_INCOMPLETE'
                );
            }
        }
    }
}
