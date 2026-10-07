<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\EntryOrders\LoadDteDTO;
use App\Application\DTOs\EntryOrders\ReceiveDTO;
use App\Application\DTOs\EntryOrders\StoreEntryOrderDTO;
use App\Application\UseCases\EntryOrders\EntryOrderUseCases;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Enums\EntryOrderStatus;
use App\Core\Enums\ReferenceMode;
use App\Core\Enums\WeighingMode;
use App\Core\Exceptions\EntryDteValidationException;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\EntryOrders\CorrectEntryOrderDteHeadCountRequest;
use App\Http\Requests\EntryOrders\LoadEntryOrderDteRequest;
use App\Http\Requests\EntryOrders\ReadTriRequest;
use App\Http\Requests\EntryOrders\ReceiveEntryOrderRequest;
use App\Http\Requests\EntryOrders\RegisterEntryRequest;
use App\Http\Requests\EntryOrders\ResolveEntryOrderIncidentRequest;
use App\Http\Requests\EntryOrders\StoreEntryOrderRequest;
use App\Http\Requests\TransferOrders\CancelTransferOrderRequest;
use App\Http\Requests\TransferOrders\TransferOrderReasonRequest;
use App\Http\Resources\EntryOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Entry orders of external livestock, in both directions: a purchase confirmed before its DTE
 * arrives (it waits, each DTE is loaded with the head it declares, then its animals are received
 * one caravan at a time), or registered together with its DTE and its animals.
 */
final class EntryOrderController extends Controller
{
    public function __construct(
        private readonly EntryOrderUseCases $useCases,
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $status = is_string($status) ? EntryOrderStatus::tryFrom(strtoupper($status))?->value : null;
        $providerId = $request->query('provider_id');
        $providerId = is_numeric($providerId) ? (int) $providerId : null;

        $withOpenIncidents = $request->query('incidents') === 'open';

        $orders = ($this->useCases->list)($this->companyId(), $status, $providerId, $withOpenIncidents);

        return response()->json(array_map(
            fn (EntryOrderEntity $order) => (new EntryOrderResource($order))->summary()->resolve($request),
            $orders
        ));
    }

    public function nextNumber(): JsonResponse
    {
        return response()->json(['number' => ($this->useCases->peekNextNumber)($this->companyId())]);
    }

    public function store(StoreEntryOrderRequest $request): JsonResponse
    {
        try {
            $dto = StoreEntryOrderDTO::fromArray($request->validated(), $this->companyId(), $request->user()?->id);
            $result = ($this->useCases->create)($dto);
        } catch (EntryOrderDomainException $e) {
            return $this->domainError($e);
        }

        return $this->withWarnings($request, $result, 201);
    }

    /**
     * "Registrar ingreso": the order, its batch and its DTE in one step.
     */
    public function register(RegisterEntryRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $dto = StoreEntryOrderDTO::fromArray($validated, $this->companyId(), $request->user()?->id);

            $result = ($this->useCases->register)(
                $dto,
                LoadDteDTO::fromArray($validated['dte']),
                (string) $validated['dte']['entered_at'],
                $validated['dte']['animals'],
                $validated['close_incomplete_reason'] ?? null
            );
        } catch (EntryDteValidationException $e) {
            return $this->dteError($e);
        } catch (EntryOrderDomainException $e) {
            return $this->domainError($e);
        }

        return $this->withWarnings($request, $result, 201);
    }

    public function update(StoreEntryOrderRequest $request, int $id): JsonResponse
    {
        try {
            $dto = StoreEntryOrderDTO::fromArray($request->validated(), $this->companyId(), $request->user()?->id);
            $result = ($this->useCases->updateDraft)($id, $dto);
        } catch (EntryOrderDomainException $e) {
            return $this->domainError($e);
        }

        return $this->withWarnings($request, $result);
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        try {
            $result = ($this->useCases->confirm)($id, $this->companyId(), $request->user()?->id);
        } catch (EntryOrderDomainException $e) {
            return $this->domainError($e);
        }

        return $this->withWarnings($request, $result);
    }

    public function loadDte(LoadEntryOrderDteRequest $request, int $id): JsonResponse
    {
        try {
            $result = ($this->useCases->loadDte)(
                $id,
                $this->companyId(),
                $request->user()?->id,
                LoadDteDTO::fromArray($request->validated())
            );
        } catch (EntryDteValidationException $e) {
            return $this->dteError($e);
        } catch (EntryOrderDomainException $e) {
            return $this->domainError($e);
        }

        return $this->withWarnings($request, $result, 201);
    }

    /**
     * "Corregir cabezas" of a DTE loaded wrong.
     */
    public function correctDteHeadCount(CorrectEntryOrderDteHeadCountRequest $request, int $id, int $dteId): JsonResponse
    {
        try {
            $result = ($this->useCases->correctDteHeadCount)(
                $id,
                $this->companyId(),
                $request->user()?->id,
                $dteId,
                (int) $request->validated('head_count'),
                (string) $request->validated('reason')
            );
        } catch (EntryOrderDomainException $e) {
            return $this->domainError($e);
        }

        return $this->withWarnings($request, $result);
    }

    /**
     * "Recibir": animals of a DTE arrived, written down by hand or on a scanned ING-03 sheet.
     */
    public function receive(ReceiveEntryOrderRequest $request, int $id): JsonResponse
    {
        try {
            $result = ($this->useCases->receive)(
                $id,
                $this->companyId(),
                $request->user()?->id,
                ReceiveDTO::fromArray($request->validated())
            );
        } catch (EntryDteValidationException $e) {
            return $this->dteError($e);
        } catch (EntryOrderDomainException $e) {
            return $this->domainError($e);
        }

        return $this->withWarnings($request, $result);
    }

    /**
     * "Adjuntar TRI": reads the caravans of the SENASA TRI of a DTE with the AI, to fill in its
     * reception. Nothing is received here.
     */
    public function readTri(ReadTriRequest $request, int $id): JsonResponse
    {
        try {
            $reading = ($this->useCases->readTri)($id, $this->companyId(), $request->file('documents', []));
        } catch (EntryOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json($reading);
    }

    public function resolveIncident(ResolveEntryOrderIncidentRequest $request, int $id, int $incidentId): JsonResponse
    {
        try {
            $order = ($this->useCases->resolveIncident)(
                $id,
                $incidentId,
                $this->companyId(),
                $request->user()?->id,
                (string) $request->validated('resolution')
            );
        } catch (EntryOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new EntryOrderResource($order));
    }

    public function show(int $id): JsonResponse
    {
        $order = ($this->useCases->get)($id, $this->companyId());

        if ($order === null) {
            return response()->json(['message' => 'La orden de ingreso no existe.', 'code' => 'ENTRY_ORDER_NOT_FOUND'], 404);
        }

        return response()->json(new EntryOrderResource($order));
    }

    public function byCode(string $code): JsonResponse
    {
        $order = ($this->useCases->findByCode)($code, $this->companyId());

        if ($order === null) {
            return response()->json(['message' => "No existe la orden de ingreso {$code}.", 'code' => 'ENTRY_ORDER_NOT_FOUND'], 404);
        }

        return response()->json(new EntryOrderResource($order));
    }

    public function printed(Request $request, int $id): JsonResponse
    {
        try {
            $order = ($this->useCases->markPrinted)($id, $this->companyId(), $request->user()?->id);
        } catch (EntryOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new EntryOrderResource($order));
    }

    /**
     * The ING-03 sheet of a DTE, to print, weighed per animal or with one average and with the
     * breed and category written or by code (without saying, like the last sheet). Answers the order with the sheet in it.
     */
    public function issueReceiptSheet(Request $request, int $id): JsonResponse
    {
        $request->validate(
            [
                'dte_id' => 'required|integer',
                'weighing_mode' => 'nullable|string|in:INDIVIDUAL,AVERAGE',
                'reference_mode' => 'nullable|string|in:WRITTEN,CODE',
            ],
            [
                'weighing_mode.in' => 'El peso de la hoja es individual (INDIVIDUAL) o promedio (AVERAGE).',
                'reference_mode.in' => 'La raza y la categoría se anotan escritas (WRITTEN) o por código (CODE).',
            ]
        );

        try {
            $result = ($this->useCases->issueReceiptSheet)(
                $id,
                $this->companyId(),
                $request->user()?->id,
                (int) $request->input('dte_id'),
                WeighingMode::tryFrom((string) $request->input('weighing_mode')),
                ReferenceMode::tryFrom((string) $request->input('reference_mode'))
            );
        } catch (EntryOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json([
            'order' => (new EntryOrderResource($result['order']))->resolve($request),
            'sheet_number' => $result['sheet_number'],
        ], 201);
    }

    public function receiptSheetPrinted(Request $request, int $id, int $sheetId): JsonResponse
    {
        try {
            $order = ($this->useCases->markReceiptSheetPrinted)($id, $this->companyId(), $request->user()?->id, $sheetId);
        } catch (EntryOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new EntryOrderResource($order));
    }

    /**
     * What an ING-03 sheet not yet printed prints: how it is weighed and how its lines name the
     * breed, coat and category.
     */
    public function configureReceiptSheet(Request $request, int $id, int $sheetId): JsonResponse
    {
        $request->validate(
            [
                'weighing_mode' => 'nullable|required_without:reference_mode|string|in:INDIVIDUAL,AVERAGE',
                'reference_mode' => 'nullable|string|in:WRITTEN,CODE',
            ],
            [
                'weighing_mode.in' => 'El peso de la hoja es individual (INDIVIDUAL) o promedio (AVERAGE).',
                'weighing_mode.required_without' => 'Indicá qué cambia en la hoja: el peso o cómo se anota la raza.',
                'reference_mode.in' => 'La raza y la categoría se anotan escritas (WRITTEN) o por código (CODE).',
            ]
        );

        try {
            $order = ($this->useCases->configureReceiptSheet)(
                $id,
                $this->companyId(),
                $request->user()?->id,
                $sheetId,
                WeighingMode::tryFrom((string) $request->input('weighing_mode')),
                ReferenceMode::tryFrom((string) $request->input('reference_mode'))
            );
        } catch (EntryOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new EntryOrderResource($order));
    }

    public function closeIncomplete(TransferOrderReasonRequest $request, int $id): JsonResponse
    {
        try {
            $order = ($this->useCases->closeIncomplete)($id, $this->companyId(), $request->user()?->id, (string) $request->validated('reason'));
        } catch (EntryOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new EntryOrderResource($order));
    }

    public function cancel(CancelTransferOrderRequest $request, int $id): JsonResponse
    {
        try {
            $order = ($this->useCases->cancel)($id, $this->companyId(), $request->user()?->id, $request->validated('reason'));
        } catch (EntryOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new EntryOrderResource($order));
    }

    /**
     * @param array{order: EntryOrderEntity, warnings: list<array<string, mixed>>} $result
     */
    private function withWarnings(Request $request, array $result, int $status = 200): JsonResponse
    {
        return response()->json([
            'order' => (new EntryOrderResource($result['order']))->resolve($request),
            'warnings' => $result['warnings'],
        ], $status);
    }

    private function companyId(): int
    {
        return (int) $this->companyContext->getCompanyId();
    }

    private function dteError(EntryDteValidationException $e): JsonResponse
    {
        return response()->json([
            'status' => 'invalid',
            'message' => $e->getMessage(),
            'header_errors' => $e->getHeaderErrors(),
            'row_errors' => $e->getRowErrors(),
        ], 422);
    }

    private function domainError(EntryOrderDomainException $e): JsonResponse
    {
        $status = $e->getErrorCode() === 'ENTRY_ORDER_NOT_FOUND' ? 404 : 422;

        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->getErrorCode(),
            'field' => $e->getField(),
        ], $status);
    }
}
