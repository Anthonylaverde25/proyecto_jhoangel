<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\EntryOrders\LoadDteDTO;
use App\Core\Entities\EntryOrderDteEntity;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Entities\EntryOrderIncidentEntity;
use App\Core\Exceptions\EntryDteValidationException;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;
use App\Models\Caravan;
use App\Models\CaravanMovement;

/**
 * Loads an official transit document (DTE) on an entry order: how many head move between the
 * establishments. Used by "Cargar DTE" and by "Registrar ingreso".
 *
 * The DTE lists no caravans: they are written down when the animals arrive
 * (EntryOrderReceptionService). Category, sex and origin are the order's. Head in excess are not
 * an error: the DTE is loaded and an incident is raised. Every problem is reported at once.
 *
 * The paper is recommended, never required: head can move without it and it is attached later, on
 * that same group of head, reaching the caravans they already became.
 *
 * Must run inside the transaction that saves the order: it leaves the DTE recorded on the order
 * for the repository to store.
 */
final class EntryOrderDteService
{
    public function __construct(private readonly IEntryOrderRepository $repository)
    {
    }

    /**
     * @return array{warnings: list<array{code: string, message: string}>, metadata: array<string, mixed>}
     *
     * @throws EntryDteValidationException
     * @throws EntryOrderDomainException
     */
    public function load(EntryOrderEntity $order, LoadDteDTO $dto, ?int $userId): array
    {
        if (!$order->getStatus()->acceptsDte()) {
            throw EntryOrderDomainException::invalid(
                "La orden {$order->getCode()} está {$order->getStatus()->label()} y no admite DTE.",
                'ENTRY_ORDER_NOT_ACCEPTING_DTE'
            );
        }

        $this->validate($order, $dto);

        $incidents = $order->recordDte(new EntryOrderDteEntity(
            id: null,
            dteNumber: $dto->dteNumber,
            dteDate: $dto->dteDate,
            headCount: $dto->headCount,
            loadedByUserId: $userId,
            observations: $dto->observations
        ), $userId);

        return [
            'warnings' => array_map(fn (EntryOrderIncidentEntity $incident) => [
                'code' => $incident->getType()->value,
                'message' => $incident->getDetail() . ' Se registró una novedad para revisar con el proveedor.',
            ], $incidents),
            'metadata' => [
                'dte_number' => $dto->dteNumber,
                'without_dte' => $dto->dteNumber === null,
                'head_count' => $dto->headCount,
                'with_dte_total' => $order->withDteCount(),
                'pending_dte' => $order->pendingDteCount(),
                'incidents' => array_map(fn (EntryOrderIncidentEntity $i) => $i->getType()->value, $incidents),
            ],
        ];
    }

    /**
     * The paper of head that moved without it. Must run inside the transaction that saves the order.
     *
     * @return array{warnings: list<array{code: string, message: string}>, metadata: array<string, mixed>}
     *
     * @throws EntryDteValidationException
     * @throws EntryOrderDomainException
     */
    public function attachDocument(EntryOrderEntity $order, int $dteId, LoadDteDTO $dto, ?int $userId): array
    {
        $this->validate($order, $dto);

        $from = $order->findDte($dteId)->getHeadCount();
        $result = $order->attachDteDocument($dteId, (string) $dto->dteNumber, (string) $dto->dteDate, $dto->headCount, $dto->observations, $userId);
        $this->stampCaravans($order, $dteId, (string) $dto->dteNumber);

        $warnings = array_map(fn (EntryOrderIncidentEntity $incident) => [
            'code' => $incident->getType()->value,
            'message' => $incident->getDetail() . ' Se registró una novedad para revisar con el proveedor.',
        ], $result['raised']);

        foreach ($result['no_longer_differ'] as $incident) {
            $warnings[] = [
                'code' => 'INCIDENT_NO_LONGER_DIFFERS',
                'message' => "El DTE deja sin diferencia la novedad \"{$incident->getType()->label()}\": resolvela con lo que se acordó.",
            ];
        }

        return [
            'warnings' => $warnings,
            'metadata' => [
                'action' => 'dte_document_attached',
                'dte_number' => $dto->dteNumber,
                'head_count' => $dto->headCount,
                'from' => $from,
                'incidents' => array_map(fn (EntryOrderIncidentEntity $i) => $i->getType()->value, $result['raised']),
            ],
        ];
    }

    /**
     * The caravans already received without the paper name it from now on, like any other: their
     * provenance and their entry movement.
     */
    private function stampCaravans(EntryOrderEntity $order, int $dteId, string $dteNumber): void
    {
        foreach ($order->findDte($dteId)->getAnimals() as $animal) {
            $caravan = Caravan::withoutGlobalScopes()->whereKey($animal->getCaravanId())->first();

            if ($caravan !== null) {
                $caravan->update(['provenance_metadata' => [...($caravan->provenance_metadata ?? []), 'dte_number' => $dteNumber]]);
            }

            $movement = $animal->getCaravanMovementId() !== null
                ? CaravanMovement::withoutGlobalScopes()->whereKey($animal->getCaravanMovementId())->first()
                : null;

            if ($movement !== null) {
                $movement->update([
                    'provenance_metadata' => [...($movement->provenance_metadata ?? []), 'dte_number' => $dteNumber],
                    'observations' => "Ingreso por DTE {$dteNumber} de la orden {$order->getCode()}",
                ]);
            }
        }
    }

    /**
     * @throws EntryDteValidationException
     */
    private function validate(EntryOrderEntity $order, LoadDteDTO $dto): void
    {
        $header = [];

        if ($dto->headCount < 1) {
            $header[] = $this->error('head_count', 'HEAD_COUNT_INVALID', $dto->dteNumber === null ? 'Indicá cuántas cabezas vienen.' : 'El DTE tiene que declarar al menos una cabeza.');
        }

        // Without the paper there is nothing else to check.
        if ($dto->dteNumber === null) {
            if ($header !== []) {
                throw new EntryDteValidationException('El ingreso tiene datos para corregir.', $header, []);
            }

            return;
        }

        $purchaseDate = $order->getTroop()->purchaseDate;

        if ($dto->dteNumber === '') {
            $header[] = $this->error('dte_number', 'DTE_NUMBER_MISSING', 'Falta el número de DTE.');
        } elseif (($loadedIn = $this->repository->orderCodeOfDte($dto->dteNumber, $order->getCompanyId())) !== null) {
            $header[] = $this->error('dte_number', 'DTE_ALREADY_LOADED', "El DTE {$dto->dteNumber} ya está cargado en la orden {$loadedIn}.");
        }

        if ($dto->dteDate === null || $dto->dteDate === '') {
            $header[] = $this->error('dte_date', 'DTE_DATE_MISSING', 'Falta la fecha del DTE.');
        } elseif ($dto->dteDate > now()->toDateString()) {
            $header[] = $this->error('dte_date', 'DATE_IN_FUTURE', 'La fecha del DTE no puede ser futura.');
        } elseif ($dto->dteDate < $purchaseDate) {
            $header[] = $this->error('dte_date', 'DTE_BEFORE_PURCHASE', "El DTE no pudo emitirse antes de la compra ({$purchaseDate}).");
        }

        if ($header !== []) {
            throw new EntryDteValidationException('El DTE tiene datos para corregir antes de cargarlo.', $header, []);
        }
    }

    /**
     * @return array{field: string, code: string, message: string}
     */
    private function error(string $field, string $code, string $message): array
    {
        return ['field' => $field, 'code' => $code, 'message' => $message];
    }
}
