<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Application\DTOs\ServiceOrders\RegisterServiceOrdersDTO;
use App\Application\Services\RegisteredBullStayRecorder;
use App\Application\Services\Stock\FieldErrorBag;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\ServiceExposureExitReason;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Exceptions\ServiceOrderSheetException;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Services\ServiceOrderCodeGenerator;
use App\Core\Services\ServiceStartPolicy;
use Illuminate\Support\Facades\DB;

/**
 * "Registrar orden": the service ended in the field and the system learns it afterwards. Each batch
 * gets an order born ended, with its real dates, waiting for its diagnosis.
 *
 * It speaks about the past, so the state of the animals today does not rule: an active order of the
 * batch does not block it (it never takes the batch), conflicts are searched by date, and a bull
 * unfit today may have served then. All or nothing across the batches.
 */
final class RegisterServiceOrdersUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $orders,
        private readonly IBatchRepository $batches,
        private readonly ServiceStartPolicy $startPolicy,
        private readonly ServiceOrderCodeGenerator $codeGenerator,
        private readonly RegisteredBullStayRecorder $bullStays
    ) {
    }

    /**
     * @return list<ServiceOrderEntity>
     *
     * @throws ServiceOrderSheetException
     */
    public function __invoke(RegisterServiceOrdersDTO $dto): array
    {
        $this->validate($dto);

        $orderIds = DB::transaction(function () use ($dto): array {
            $ids = [];
            foreach ($dto->lots as $lot) {
                $ids[] = $this->register($dto, $lot);
            }

            return $ids;
        });

        return array_map(
            fn (int $id) => $this->orders->findById($id, $dto->companyId)
                ?? throw ServiceOrderDomainException::domainError('Error al recuperar la orden registrada.'),
            $orderIds
        );
    }

    /** @param array{batch_id: int, female_caravan_ids: int[], bulls: list<\App\Application\DTOs\ServiceOrders\RegisteredBullStayDTO>} $lot */
    private function register(RegisterServiceOrdersDTO $dto, array $lot): int
    {
        $natural = $dto->serviceMethod->needsBulls();
        $code = $this->codeGenerator->next($dto->companyId, $dto->startDate);

        $orderId = $this->orders->createRegistered(
            [
                'company_id'            => $dto->companyId,
                'batch_id'              => $lot['batch_id'],
                'code'                  => $code,
                'status'                => ($natural ? ServiceOrderStatus::BULLS_WITHDRAWN : ServiceOrderStatus::SERVICE_ENDED)->value,
                'service_type'          => count($lot['bulls']) === 1 ? 'single' : 'multi',
                'service_method'        => $dto->serviceMethod->value,
                'is_controlled_service' => false,
                'planned_start_date'    => $dto->startDate,
                'planned_end_date'      => $dto->endDate,
                'actual_start_date'     => $dto->startDate,
                'actual_end_date'       => $dto->endDate,
                'requested_by_user_id'  => $dto->userId,
                'approved_by_user_id'   => $dto->userId,
                'approved_at'           => now(),
                'executed_at'           => now(),
                'observations'          => $dto->observations,
            ],
            $lot['female_caravan_ids'],
            array_map(fn ($bull) => [
                'male_caravan_id' => $bull->maleCaravanId,
                'joined_at'       => $bull->joinedAt,
                'retired_at'      => $bull->retiredAt,
            ], $lot['bulls']),
            $natural ? ServiceExposureExitReason::BULLS_WITHDRAWN : ServiceExposureExitReason::SERVICE_CLOSED,
            $dto->userId,
            'Orden registrada después del hecho'
        );

        foreach ($lot['bulls'] as $bull) {
            $this->bullStays->record($dto->companyId, $lot['batch_id'], $code, $bull);
        }

        return $orderId;
    }

    /** Every broken rule at once, on the batch or bull cell that broke it. */
    private function validate(RegisterServiceOrdersDTO $dto): void
    {
        $errors = new FieldErrorBag();

        if ($dto->endDate > now()->toDateString()) {
            $errors->add('end_date', 'La fecha de fin no puede ser futura: si el servicio sigue, usá "Nueva orden" con su fecha de inicio.');
        }
        if ($dto->startDate > $dto->endDate) {
            $errors->add('start_date', 'La fecha de inicio no puede ser posterior a la de fin.');
        }
        $errors->throwIfAny(ServiceOrderSheetException::class);

        $stays = [];
        foreach ($dto->lots as $index => $lot) {
            $this->validateBatch($dto, $lot['batch_id'], "lots.{$index}", $errors);
            $this->validateFemales($dto, $lot['female_caravan_ids'], "lots.{$index}", $errors);
            if ($dto->serviceMethod->needsBulls()) {
                $this->validateBulls($dto, $lot, "lots.{$index}", $stays, $errors);
            }
        }

        $errors->throwIfAny(ServiceOrderSheetException::class);
    }

    private function validateBatch(RegisterServiceOrdersDTO $dto, int $batchId, string $key, FieldErrorBag $errors): void
    {
        $batch = $this->batches->findById($batchId);
        if ($batch === null) {
            $errors->add($key, "El lote con ID {$batchId} no existe.");

            return;
        }

        try {
            $this->startPolicy->assertBatchCanStartService($batch, null);
        } catch (ServiceOrderDomainException $e) {
            $errors->add($key, $e->getMessage());

            return;
        }

        $overlapping = $this->orders->findOverlappingOrderOnBatch($batchId, $dto->companyId, $dto->startDate, $dto->endDate);
        if ($overlapping !== null) {
            $errors->add($key, "{$batch->getName()} ya tuvo la orden {$overlapping} en esas fechas.");
        }
    }

    /** @param int[] $femaleIds */
    private function validateFemales(RegisterServiceOrdersDTO $dto, array $femaleIds, string $key, FieldErrorBag $errors): void
    {
        if ($femaleIds === []) {
            $errors->add("{$key}.females", 'Elegí al menos un vientre.');

            return;
        }

        $sexes = $this->orders->verifyAnimalsSexAndOwnership($femaleIds, $dto->companyId);
        foreach ($femaleIds as $femaleId) {
            if (($sexes[$femaleId] ?? null) !== AnimalSex::FEMALE->value) {
                $errors->add("{$key}.females", "El animal con ID {$femaleId} no es un vientre de la empresa.");
            }
        }

        $pregnant = $this->orders->findFemalesWithCurrentGestation($femaleIds, $dto->companyId);
        if ($pregnant !== []) {
            $errors->add("{$key}.females", 'Tienen una preñez vigente: ' . implode(', ', $pregnant) . '.');
        }

        foreach ($this->orders->findOverlappingFemales($femaleIds, $dto->companyId, $dto->startDate, $dto->endDate) as $overlap) {
            $errors->add("{$key}.females", "{$overlap['identification']} estuvo en la orden {$overlap['code']} en esas fechas.");
        }
    }

    /**
     * @param array{batch_id: int, female_caravan_ids: int[], bulls: list<\App\Application\DTOs\ServiceOrders\RegisteredBullStayDTO>} $lot
     * @param array<int, list<array{from: string, until: string}>> $stays stays already declared in this sheet, by bull
     */
    private function validateBulls(RegisterServiceOrdersDTO $dto, array $lot, string $key, array &$stays, FieldErrorBag $errors): void
    {
        if ($lot['bulls'] === []) {
            $errors->add("{$key}.bulls", 'Indicá al menos un toro.');

            return;
        }

        $maleIds = array_map(fn ($bull) => $bull->maleCaravanId, $lot['bulls']);
        $sexes = $this->orders->verifyAnimalsSexAndOwnership($maleIds, $dto->companyId);
        $batchIds = array_merge(...array_map(fn ($bull) => [$bull->originBatchId, $bull->destinationBatchId], $lot['bulls']));
        $known = $this->orders->findCompanyBatchIds($batchIds, $dto->companyId);

        foreach ($lot['bulls'] as $row => $bull) {
            $cell = "{$key}.bulls.{$row}";
            if (($sexes[$bull->maleCaravanId] ?? null) !== AnimalSex::MALE->value) {
                $errors->add("{$cell}.male_caravan_id", 'No es un macho de la empresa.');
            }
            if (!in_array($bull->originBatchId, $known, true)) {
                $errors->add("{$cell}.origin_batch_id", 'El lote de origen no existe en la empresa.');
            }
            if (!in_array($bull->destinationBatchId, $known, true)) {
                $errors->add("{$cell}.destination_batch_id", 'El lote de destino no existe en la empresa.');
            }
            if ($bull->destinationBatchId === $lot['batch_id']) {
                $errors->add("{$cell}.destination_batch_id", 'Si el toro sigue con los vientres, el servicio no terminó: elegí otro lote o usá "Nueva orden".');
            }
            if ($bull->joinedAt < $dto->startDate || $bull->joinedAt > $dto->endDate) {
                $errors->add("{$cell}.joined_at", 'La entrada debe estar dentro de las fechas del servicio.');
            }
            if ($bull->retiredAt < $bull->joinedAt || $bull->retiredAt > $dto->endDate) {
                $errors->add("{$cell}.retired_at", 'La salida debe ser posterior a la entrada y estar dentro de las fechas del servicio.');
            }

            $overlap = $this->orders->findOverlappingBullOrder($bull->maleCaravanId, $dto->companyId, $bull->joinedAt, $bull->retiredAt);
            if ($overlap !== null) {
                $errors->add("{$cell}.male_caravan_id", "Este toro estuvo en la orden {$overlap} en esas fechas.");
            }
            foreach ($stays[$bull->maleCaravanId] ?? [] as $stay) {
                if ($bull->joinedAt < $stay['until'] && $bull->retiredAt > $stay['from']) {
                    $errors->add("{$cell}.male_caravan_id", 'Este toro ya está en otra fila con esas fechas.');
                    break;
                }
            }
            $stays[$bull->maleCaravanId][] = ['from' => $bull->joinedAt, 'until' => $bull->retiredAt];
        }
    }
}
