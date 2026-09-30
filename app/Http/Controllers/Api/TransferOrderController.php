<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\TransferOrders\EmitTransferOrderDTO;
use App\Application\DTOs\TransferOrders\RegisterFieldData;
use App\Application\UseCases\TransferOrders\TransferOrderUseCases;
use App\Core\Enums\TransferOrderKind;
use App\Core\Enums\TransferOrderStatus;
use App\Core\Exceptions\Cact01ValidationException;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\TransferOrderDomainException;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\TransferOrders\CancelTransferOrderRequest;
use App\Http\Requests\TransferOrders\EmitTransferOrderRequest;
use App\Http\Requests\TransferOrders\ExecuteTransferOrderRequest;
use App\Http\Requests\TransferOrders\RegisterTransferRequest;
use App\Http\Requests\TransferOrders\TransferOrderReasonRequest;
use App\Http\Resources\TransferOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TransferOrderController extends Controller
{
    public function __construct(
        private readonly TransferOrderUseCases $useCases,
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $status = is_string($status) ? TransferOrderStatus::tryFrom(strtoupper($status))?->value : null;
        $sourceBatchId = $request->query('source_batch_id');
        $kind = $request->query('kind');
        $kind = is_string($kind) ? TransferOrderKind::tryFrom(strtoupper($kind))?->value : null;

        $orders = ($this->useCases->list)(
            $this->companyId(),
            $status,
            is_numeric($sourceBatchId) ? (int) $sourceBatchId : null,
            $kind
        );

        return response()->json(array_map(
            fn ($order) => (new TransferOrderResource($order))->summary()->resolve($request),
            $orders
        ));
    }

    public function store(EmitTransferOrderRequest $request): JsonResponse
    {
        try {
            $dto = EmitTransferOrderDTO::fromArray($request->validated(), $this->companyId(), $request->user()?->id);
            $order = ($this->useCases->create)($dto);
        } catch (TransferOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new TransferOrderResource($order), 201);
    }

    /**
     * "Registrar transferencia": an order born executed, for a movement that already happened.
     */
    public function register(RegisterTransferRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $dto = EmitTransferOrderDTO::fromArray(
                [...$validated, 'issue' => true],
                $this->companyId(),
                $request->user()?->id
            );
            $result = ($this->useCases->register)($dto, RegisterFieldData::mapFromAnimals($validated['animals']));
        } catch (Cact01ValidationException $e) {
            return response()->json([
                'status' => 'invalid',
                'message' => $e->getMessage(),
                'header_errors' => $e->getHeaderErrors(),
                'row_errors' => $e->getRowErrors(),
            ], 422);
        } catch (TransferOrderDomainException $e) {
            return $this->domainError($e);
        } catch (DomainException $e) {
            return response()->json(['status' => 'invalid', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'order' => (new TransferOrderResource($result['order']))->resolve($request),
            'warnings' => $result['warnings'],
        ], 201);
    }

    /**
     * "Guardar cambios" on a draft: the same payload as creating it.
     */
    public function update(EmitTransferOrderRequest $request, int $id): JsonResponse
    {
        try {
            $dto = EmitTransferOrderDTO::fromArray($request->validated(), $this->companyId(), $request->user()?->id);
            $order = ($this->useCases->updateDraft)($id, $dto);
        } catch (TransferOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new TransferOrderResource($order));
    }

    public function issue(Request $request, int $id): JsonResponse
    {
        try {
            $order = ($this->useCases->issue)($id, $this->companyId(), $request->user()?->id);
        } catch (TransferOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new TransferOrderResource($order));
    }

    public function show(int $id): JsonResponse
    {
        $order = ($this->useCases->get)($id, $this->companyId());

        if ($order === null) {
            return response()->json(['message' => 'La orden de transferencia no existe.', 'code' => 'TRANSFER_ORDER_NOT_FOUND'], 404);
        }

        return response()->json(new TransferOrderResource($order));
    }

    public function byCode(string $code): JsonResponse
    {
        $order = ($this->useCases->findByCode)($code, $this->companyId());

        if ($order === null) {
            return response()->json(['message' => "No existe la orden {$code}.", 'code' => 'TRANSFER_ORDER_NOT_FOUND'], 404);
        }

        return response()->json(new TransferOrderResource($order));
    }

    public function printed(Request $request, int $id): JsonResponse
    {
        try {
            $order = ($this->useCases->markPrinted)($id, $this->companyId(), $request->user()?->id);
        } catch (TransferOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new TransferOrderResource($order));
    }

    public function closeIncomplete(TransferOrderReasonRequest $request, int $id): JsonResponse
    {
        try {
            $order = ($this->useCases->closeIncomplete)($id, $this->companyId(), $request->user()?->id, (string) $request->validated('reason'));
        } catch (TransferOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new TransferOrderResource($order));
    }

    public function cancel(CancelTransferOrderRequest $request, int $id): JsonResponse
    {
        try {
            $order = ($this->useCases->cancel)($id, $this->companyId(), $request->user()?->id, $request->validated('reason'));
        } catch (TransferOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new TransferOrderResource($order));
    }

    /**
     * Executes the order from the screen. Answers with the same shape as the CACT-01 endpoint,
     * because it is the same movement.
     */
    public function execute(ExecuteTransferOrderRequest $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = ($this->useCases->execute)(
                $id,
                $this->companyId(),
                $request->user()?->id,
                $validated['movement_date'] ?? null,
                RegisterFieldData::mapFromAnimals($validated['animals'] ?? [])
            );
        } catch (Cact01ValidationException $e) {
            return response()->json([
                'status' => 'invalid',
                'message' => $e->getMessage(),
                'header_errors' => $e->getHeaderErrors(),
                'row_errors' => $e->getRowErrors(),
            ], 422);
        } catch (TransferOrderDomainException $e) {
            return $this->domainError($e);
        } catch (DomainException $e) {
            return response()->json(['status' => 'invalid', 'message' => $e->getMessage()], 422);
        }

        $moved = array_sum(array_column($result['destinations'], 'count'));

        return response()->json([
            'status' => 'success',
            'message' => "Orden {$result['transfer_order']['code']} ejecutada: {$moved} animales movidos.",
            'data' => $result,
        ], 201);
    }

    private function companyId(): int
    {
        return (int) $this->companyContext->getCompanyId();
    }

    private function domainError(TransferOrderDomainException $e): JsonResponse
    {
        $status = $e->getErrorCode() === 'TRANSFER_ORDER_NOT_FOUND' ? 404 : 422;

        return response()->json(['message' => $e->getMessage(), 'code' => $e->getErrorCode()], $status);
    }
}
