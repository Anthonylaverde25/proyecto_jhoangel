<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\WeaningOrders\EmitWeaningOrderDTO;
use App\Application\DTOs\WeaningOrders\WeaningFieldData;
use App\Application\UseCases\WeaningOrders\WeaningOrderUseCases;
use App\Core\Enums\TransferOrderKind;
use App\Core\Enums\TransferOrderStatus;
use App\Core\Exceptions\Dest01ValidationException;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\WeaningOrderDomainException;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\TransferOrders\CancelTransferOrderRequest;
use App\Http\Requests\TransferOrders\TransferOrderReasonRequest;
use App\Http\Requests\WeaningOrders\EmitWeaningOrderRequest;
use App\Http\Requests\WeaningOrders\ExecuteWeaningOrderRequest;
use App\Http\Requests\WeaningOrders\RegisterWeaningRequest;
use App\Http\Resources\Dest01ResultResource;
use App\Http\Resources\WeaningOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Weaning orders, in both directions: issued before the chute and executed later (from the screen or
 * by scanning their DEST-01 sheet), or registered after the weaning already happened.
 */
final class WeaningOrderController extends Controller
{
    public function __construct(
        private readonly WeaningOrderUseCases $useCases,
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $status = is_string($status) ? TransferOrderStatus::tryFrom(strtoupper($status))?->value : null;
        $kind = $request->query('kind');
        $kind = is_string($kind) ? TransferOrderKind::tryFrom(strtoupper($kind))?->value : null;

        $orders = ($this->useCases->list)($this->companyId(), $status, $kind);

        return response()->json(array_map(
            fn ($order) => (new WeaningOrderResource($order))->summary()->resolve($request),
            $orders
        ));
    }

    public function store(EmitWeaningOrderRequest $request): JsonResponse
    {
        try {
            $dto = EmitWeaningOrderDTO::fromArray($request->validated(), $this->companyId(), $request->user()?->id);
            $order = ($this->useCases->create)($dto);
        } catch (WeaningOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new WeaningOrderResource($order), 201);
    }

    /**
     * "Registrar destete": an order born executed, for a weaning that already happened.
     */
    public function register(RegisterWeaningRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $dto = EmitWeaningOrderDTO::fromArray([...$validated, 'issue' => true], $this->companyId(), $request->user()?->id);
            $result = ($this->useCases->register)($dto, WeaningFieldData::mapFromAnimals($validated['animals']));
        } catch (Dest01ValidationException $e) {
            return $this->sheetError($e);
        } catch (WeaningOrderDomainException $e) {
            return $this->domainError($e);
        } catch (DomainException $e) {
            return response()->json(['status' => 'invalid', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'order' => (new WeaningOrderResource($result['order']))->resolve($request),
            'warnings' => $result['warnings'],
        ], 201);
    }

    public function update(EmitWeaningOrderRequest $request, int $id): JsonResponse
    {
        try {
            $dto = EmitWeaningOrderDTO::fromArray($request->validated(), $this->companyId(), $request->user()?->id);
            $order = ($this->useCases->updateDraft)($id, $dto);
        } catch (WeaningOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new WeaningOrderResource($order));
    }

    public function issue(Request $request, int $id): JsonResponse
    {
        try {
            $order = ($this->useCases->issue)($id, $this->companyId(), $request->user()?->id);
        } catch (WeaningOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new WeaningOrderResource($order));
    }

    public function show(int $id): JsonResponse
    {
        $order = ($this->useCases->get)($id, $this->companyId());

        if ($order === null) {
            return response()->json(['message' => 'La orden de destete no existe.', 'code' => 'WEANING_ORDER_NOT_FOUND'], 404);
        }

        return response()->json(new WeaningOrderResource($order));
    }

    public function byCode(string $code): JsonResponse
    {
        $order = ($this->useCases->findByCode)($code, $this->companyId());

        if ($order === null) {
            return response()->json(['message' => "No existe la orden de destete {$code}.", 'code' => 'WEANING_ORDER_NOT_FOUND'], 404);
        }

        return response()->json(new WeaningOrderResource($order));
    }

    public function printed(Request $request, int $id): JsonResponse
    {
        try {
            $order = ($this->useCases->markPrinted)($id, $this->companyId(), $request->user()?->id);
        } catch (WeaningOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new WeaningOrderResource($order));
    }

    public function closeIncomplete(TransferOrderReasonRequest $request, int $id): JsonResponse
    {
        try {
            $order = ($this->useCases->closeIncomplete)($id, $this->companyId(), $request->user()?->id, (string) $request->validated('reason'));
        } catch (WeaningOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new WeaningOrderResource($order));
    }

    public function cancel(CancelTransferOrderRequest $request, int $id): JsonResponse
    {
        try {
            $order = ($this->useCases->cancel)($id, $this->companyId(), $request->user()?->id, $request->validated('reason'));
        } catch (WeaningOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new WeaningOrderResource($order));
    }

    /**
     * Executes the order from the screen. Answers with the same shape as the DEST-01 endpoint,
     * because it is the same weaning.
     */
    public function execute(ExecuteWeaningOrderRequest $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = ($this->useCases->execute)(
                $id,
                $this->companyId(),
                $request->user()?->id,
                $validated['weaning_date'] ?? null,
                WeaningFieldData::mapFromAnimals($validated['animals'] ?? [])
            );
        } catch (Dest01ValidationException $e) {
            return $this->sheetError($e);
        } catch (WeaningOrderDomainException $e) {
            return $this->domainError($e);
        } catch (DomainException $e) {
            return response()->json(['status' => 'invalid', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => Dest01ResultResource::message($result),
            'data' => (new Dest01ResultResource($result))->resolve($request),
        ], 201);
    }

    private function companyId(): int
    {
        return (int) $this->companyContext->getCompanyId();
    }

    private function sheetError(Dest01ValidationException $e): JsonResponse
    {
        return response()->json([
            'status' => 'invalid',
            'message' => $e->getMessage(),
            'header_errors' => $e->getHeaderErrors(),
            'row_errors' => $e->getRowErrors(),
        ], 422);
    }

    private function domainError(WeaningOrderDomainException $e): JsonResponse
    {
        $status = $e->getErrorCode() === 'WEANING_ORDER_NOT_FOUND' ? 404 : 422;

        return response()->json(['message' => $e->getMessage(), 'code' => $e->getErrorCode()], $status);
    }
}
