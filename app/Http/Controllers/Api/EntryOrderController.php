<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\EntryOrders\LoadDteDTO;
use App\Application\DTOs\EntryOrders\StoreEntryOrderDTO;
use App\Application\UseCases\EntryOrders\EntryOrderUseCases;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Enums\EntryOrderStatus;
use App\Core\Exceptions\EntryDteValidationException;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\EntryOrders\LoadEntryOrderDteRequest;
use App\Http\Requests\EntryOrders\RegisterEntryRequest;
use App\Http\Requests\EntryOrders\StoreEntryOrderRequest;
use App\Http\Requests\TransferOrders\CancelTransferOrderRequest;
use App\Http\Requests\TransferOrders\TransferOrderReasonRequest;
use App\Http\Resources\EntryOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Entry orders of external livestock, in both directions: a purchase confirmed before its DTE
 * arrives (it waits, then each DTE is loaded), or registered together with its DTE.
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

        $orders = ($this->useCases->list)($this->companyId(), $status, $providerId);

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
