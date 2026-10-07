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

/**
 * Loads an official transit document (DTE) on an entry order: how many head move between the
 * establishments. Used by "Cargar DTE" and by "Registrar ingreso".
 *
 * The DTE lists no caravans: they are written down when the animals arrive
 * (EntryOrderReceptionService). Category, sex and origin are the order's. Head in excess are not
 * an error: the DTE is loaded and an incident is raised. Every problem is reported at once.
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
                'head_count' => $dto->headCount,
                'with_dte_total' => $order->withDteCount(),
                'pending_dte' => $order->pendingDteCount(),
                'incidents' => array_map(fn (EntryOrderIncidentEntity $i) => $i->getType()->value, $incidents),
            ],
        ];
    }

    /**
     * @throws EntryDteValidationException
     */
    private function validate(EntryOrderEntity $order, LoadDteDTO $dto): void
    {
        $purchaseDate = $order->getTroop()->purchaseDate;
        $header = [];

        if ($dto->dteNumber === '') {
            $header[] = $this->error('dte_number', 'DTE_NUMBER_MISSING', 'Falta el número de DTE.');
        } elseif (($loadedIn = $this->repository->orderCodeOfDte($dto->dteNumber, $order->getCompanyId())) !== null) {
            $header[] = $this->error('dte_number', 'DTE_ALREADY_LOADED', "El DTE {$dto->dteNumber} ya está cargado en la orden {$loadedIn}.");
        }

        if ($dto->dteDate > now()->toDateString()) {
            $header[] = $this->error('dte_date', 'DATE_IN_FUTURE', 'La fecha del DTE no puede ser futura.');
        } elseif ($dto->dteDate < $purchaseDate) {
            $header[] = $this->error('dte_date', 'DTE_BEFORE_PURCHASE', "El DTE no pudo emitirse antes de la compra ({$purchaseDate}).");
        }

        if ($dto->headCount < 1) {
            $header[] = $this->error('head_count', 'HEAD_COUNT_INVALID', 'El DTE tiene que declarar al menos una cabeza.');
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
