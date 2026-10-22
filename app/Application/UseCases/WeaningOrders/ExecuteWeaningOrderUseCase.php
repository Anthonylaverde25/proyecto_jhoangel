<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeaningOrders;

use App\Application\DTOs\Dest01\Dest01SubmissionDTO;
use App\Application\DTOs\WeaningOrders\WeaningExecutionDeclarationDTO;
use App\Application\DTOs\WeaningOrders\WeaningFieldData;
use App\Application\Services\WeaningDeviationRecorder;
use App\Application\Services\WeaningOrderRosterBuilder;
use App\Application\Services\WeaningOrderSubmissionBuilder;
use App\Application\UseCases\WorkTemplates\ProcessDest01SubmissionUseCase;
use App\Core\Entities\WeaningOrderEntity;
use App\Core\Exceptions\Dest01ValidationException;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\WeaningOrderDomainException;
use App\Core\Interfaces\IWeaningOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Ejecutar orden" from the screen: weans the order's pending calves.
 *
 * The submission is built from the ORDER, and goes through the DEST-01 processing like a scanned
 * sheet would — the one place that weans calves — marked as coming from the screen. The day it
 * happened is declared (today by default); the weights, notes and, when the order left it for the
 * chute, the category of each calf travel as field data. With `$onlyCaravanIds` it weans just
 * those pending calves ("Registrar destetadas") and the order is left PARTIAL.
 *
 * What happened may differ from the plan: a `$declaration` says where those calves went and how
 * they were weaned. The order gains the destinations it lacked and its lines point to them (the
 * plan stays on each line), saved with a history entry that details the deviation before the
 * weaning, in the same transaction — if the weaning fails, the order is left as it was.
 */
final class ExecuteWeaningOrderUseCase
{
    public function __construct(
        private readonly IWeaningOrderRepository $repository,
        private readonly ProcessDest01SubmissionUseCase $processDest01,
        private readonly WeaningOrderSubmissionBuilder $submissions,
        private readonly WeaningOrderRosterBuilder $roster,
        private readonly WeaningDeviationRecorder $deviations
    ) {
    }

    /**
     * @param array<int, WeaningFieldData> $fieldDataByCaravanId
     * @param list<int>|null $onlyCaravanIds null = every pending calf
     * @param WeaningExecutionDeclarationDTO|null $declaration where the calves went and how, when it differs from the plan
     * @return array<string, mixed> the DEST-01 result
     *
     * @throws Dest01ValidationException
     * @throws DomainException
     */
    public function __invoke(
        int $id,
        int $companyId,
        ?int $userId,
        ?string $weaningDate = null,
        array $fieldDataByCaravanId = [],
        ?array $onlyCaravanIds = null,
        ?WeaningExecutionDeclarationDTO $declaration = null
    ): array
    {
        $order = $this->repository->findById($id, $companyId) ?? throw WeaningOrderDomainException::notFound();

        if (!$order->getStatus()->isOpen()) {
            throw WeaningOrderDomainException::domainError(
                $order->getStatus()->isEditable()
                    ? "La orden {$order->getCode()} es un borrador: emitila antes de ejecutarla."
                    : "La orden {$order->getCode()} está {$order->getStatus()->label()}: no admite más destetes.",
                'WEANING_ORDER_NOT_EXECUTABLE'
            );
        }

        $date = $weaningDate !== null
            ? substr($weaningDate, 0, 10)
            : (new \DateTimeImmutable('today'))->format('Y-m-d');

        return DB::transaction(function () use ($order, $companyId, $userId, $date, $fieldDataByCaravanId, $onlyCaravanIds, $declaration): array {
            if ($declaration !== null) {
                $order = $this->recordDeviations($order, $companyId, $userId, $onlyCaravanIds, $declaration, $date);
            }

            return ($this->processDest01)(
                $this->submissions->fromOrder(
                    $order,
                    $date,
                    Dest01SubmissionDTO::ORIGIN_SCREEN,
                    $userId,
                    $fieldDataByCaravanId,
                    $onlyCaravanIds,
                    $declaration?->weaningType
                )
            );
        });
    }

    /**
     * What was declared at weaning and differs from the plan, written onto the order before it is
     * executed: the destinations it lacked, where each calf went, and one history entry with every
     * calf sent elsewhere and the weaning type when it changed. Following the plan writes nothing.
     *
     * @param list<int>|null $onlyCaravanIds
     *
     * @throws WeaningOrderDomainException
     */
    private function recordDeviations(
        WeaningOrderEntity $order,
        int $companyId,
        ?int $userId,
        ?array $onlyCaravanIds,
        WeaningExecutionDeclarationDTO $declaration,
        string $date
    ): WeaningOrderEntity {
        if ($declaration->destinations !== []) {
            [$destinations, $orderKeyByScreenKey] = $this->roster->destinations(
                $declaration->destinations,
                $this->roster->destinationActivityId($companyId)
            );

            $keyByCaravanId = [];
            foreach ($declaration->destinationKeyByCaravanId as $caravanId => $screenKey) {
                $keyByCaravanId[$caravanId] = $orderKeyByScreenKey[$screenKey]
                    ?? throw WeaningOrderDomainException::domainError('Una cría apunta a un lote de destete que no se declaró.', 'UNKNOWN_DESTINATION');
            }

            $order->redeclareDestinations($destinations, $keyByCaravanId);
        }

        $caravanIds = $onlyCaravanIds ?? array_map(fn ($line) => $line->getCaravanId(), $order->pendingAnimals());
        $deviations = $order->deviationsFor($caravanIds, $declaration->weaningType);

        if ($deviations === []) {
            return $order;
        }

        $saved = $this->repository->save($order, $userId, 'Desvío respecto de lo planificado', ['kind' => 'DEVIATION', ...$deviations]);

        // The same deviation, calf by calf, for the audit tab.
        $this->deviations->declaredAtWeaning($saved, $caravanIds, $declaration->weaningType, $date, $userId);

        return $saved;
    }
}
