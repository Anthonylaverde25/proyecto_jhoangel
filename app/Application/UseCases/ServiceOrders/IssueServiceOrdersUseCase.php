<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Application\DTOs\ServiceOrders\IssueServiceOrdersDTO;
use App\Application\DTOs\ServiceOrders\ServiceOrderLotDTO;
use App\Application\DTOs\ServiceOrders\StartBatchServiceOrderDTO;
use App\Application\Services\PendingTactoFemales;
use App\Application\Services\Stock\FieldErrorBag;
use App\Application\UseCases\Batches\StartBatchServiceOrderUseCase;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Exceptions\ServiceOrderSheetException;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Nueva orden": one form, one order per batch, with the rules of "Iniciar servicio". The day says
 * what happens: today or earlier the service starts; a future day leaves the order planned.
 *
 * All or nothing: if one batch fails, no order exists and no bull moved.
 */
final class IssueServiceOrdersUseCase
{
    public function __construct(
        private readonly StartBatchServiceOrderUseCase $startService,
        private readonly PlanServiceOrderUseCase $planService,
        private readonly IServiceOrderRepository $orders,
        private readonly ICaravanRepository $caravans,
        private readonly PendingTactoFemales $pendingTacto
    ) {
    }

    /**
     * @return array{orders: list<ServiceOrderEntity>, planned: bool, warnings: list<string>}
     *
     * @throws ServiceOrderSheetException
     */
    public function __invoke(IssueServiceOrdersDTO $dto): array
    {
        $this->assertBullsInOneBatch($dto);
        $planned = $dto->startDate > now()->toDateString();

        return DB::transaction(function () use ($dto, $planned): array {
            $orders = [];
            $warnings = [];

            foreach ($dto->lots as $index => $lot) {
                try {
                    if ($planned) {
                        $result = ($this->planService)($dto, $lot);
                        $orders[] = $result['order'];
                        array_push($warnings, ...$result['warnings']);
                    } else {
                        $this->assertAnimalsAreFree($dto, $lot);
                        $started = ($this->startService)($this->startDto($dto, $lot));
                        $orders[] = $started;
                        $pending = $this->pendingTacto->of($started->getFemaleCaravanIds(), $dto->companyId, $started->getId());
                        if ($pending !== []) {
                            $warnings[] = "Quedaron expuestas con el tacto pendiente en otra orden: {$this->pendingTacto->describe($pending)}. Tactalas o apartalas.";
                        }
                    }
                } catch (ServiceOrderSheetException $e) {
                    throw $e;
                } catch (DomainException $e) {
                    throw ServiceOrderSheetException::on("lots.{$index}", $e->getMessage());
                }
            }

            return ['orders' => $orders, 'planned' => $planned, 'warnings' => $warnings];
        });
    }

    private function startDto(IssueServiceOrdersDTO $dto, ServiceOrderLotDTO $lot): StartBatchServiceOrderDTO
    {
        return new StartBatchServiceOrderDTO(
            batchId: $lot->batchId,
            companyId: $dto->companyId,
            userId: $dto->userId,
            maleCaravanIds: $lot->maleCaravanIds,
            selectedFemaleCaravanIds: $lot->selectedFemaleCaravanIds,
            plannedStartDate: $dto->startDate,
            plannedEndDate: $dto->endDate,
            serviceType: $lot->serviceType,
            isControlledService: $lot->isControlledService,
            observations: $dto->observations,
            serviceMethod: $dto->serviceMethod,
            plannedSemen: $lot->plannedSemen
        );
    }

    /** A bull serves one batch at a time. */
    private function assertBullsInOneBatch(IssueServiceOrdersDTO $dto): void
    {
        $errors = new FieldErrorBag();
        $seen = [];
        foreach ($dto->lots as $index => $lot) {
            foreach ($lot->maleCaravanIds as $maleId) {
                if (isset($seen[$maleId]) && !$errors->has("lots.{$index}")) {
                    $errors->add("lots.{$index}", "El toro {$this->tagOf($maleId)} está elegido en más de un lote.");
                }
                $seen[$maleId] = true;
            }
        }
        $errors->throwIfAny(ServiceOrderSheetException::class);
    }

    /** Names the animal taken by another active order, instead of its internal id. */
    private function assertAnimalsAreFree(IssueServiceOrdersDTO $dto, ServiceOrderLotDTO $lot): void
    {
        $conflicts = $this->orders->findActiveOrdersByCaravans(
            array_merge($lot->maleCaravanIds, $lot->selectedFemaleCaravanIds ?? $this->caravans->findServiceableFemaleIdsInBatch($lot->batchId)),
            $dto->companyId
        );
        if ($conflicts !== []) {
            throw ServiceOrderDomainException::domainError("{$this->tagOf((int) reset($conflicts))} ya está en otra orden de servicio activa.");
        }
    }

    private function tagOf(int $caravanId): string
    {
        return $this->caravans->findById($caravanId)?->getIdentification()->getValue() ?? "#{$caravanId}";
    }
}
