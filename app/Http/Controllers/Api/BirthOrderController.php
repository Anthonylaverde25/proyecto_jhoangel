<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\BirthOrders\BirthFieldData;
use App\Application\DTOs\BirthOrders\EmitBirthOrderDTO;
use App\Application\UseCases\BirthOrders\BirthOrderUseCases;
use App\Core\Enums\TransferOrderKind;
use App\Core\Enums\TransferOrderStatus;
use App\Core\Exceptions\BirthOrderDomainException;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\Par01ValidationException;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\BirthOrders\EmitBirthOrderRequest;
use App\Http\Requests\BirthOrders\ExecuteBirthOrderRequest;
use App\Http\Requests\BirthOrders\RegisterBirthsRequest;
use App\Http\Requests\TransferOrders\CancelTransferOrderRequest;
use App\Http\Requests\TransferOrders\TransferOrderReasonRequest;
use App\Http\Resources\BirthOrderResource;
use App\Http\Resources\Par01ResultResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Birth orders, in both directions: issued before the calving rounds and fulfilled over several of
 * them (from the screen or by scanning their PAR-01 sheet), or registered after the calvings
 * already happened.
 */
final class BirthOrderController extends Controller
{
    public function __construct(
        private readonly BirthOrderUseCases $useCases,
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $status = is_string($status) ? TransferOrderStatus::tryFrom(strtoupper($status))?->value : null;
        $kind = $request->query('kind');
        $kind = is_string($kind) ? TransferOrderKind::tryFrom(strtoupper($kind))?->value : null;

        $overdueOnly = $request->boolean('overdue');

        $orders = ($this->useCases->list)($this->companyId(), $status, $kind, $overdueOnly);

        return response()->json(array_map(
            fn ($order) => (new BirthOrderResource($order))->summary()->resolve($request),
            $orders
        ));
    }

    /**
     * The females held by an open birth order: the screen that starts a new one shows them as taken.
     */
    public function openMothers(): JsonResponse
    {
        $mothers = ($this->useCases->openMothers)($this->companyId());

        return response()->json(array_map(
            fn (int $caravanId, string $code) => ['caravan_id' => $caravanId, 'code' => $code],
            array_keys($mothers),
            array_values($mothers)
        ));
    }

    public function store(EmitBirthOrderRequest $request): JsonResponse
    {
        try {
            $dto = EmitBirthOrderDTO::fromArray($request->validated(), $this->companyId(), $request->user()?->id);
            $order = ($this->useCases->create)($dto);
        } catch (BirthOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new BirthOrderResource($order), 201);
    }

    /**
     * "Registrar partos": an order born executed, for calvings that already happened.
     */
    public function register(RegisterBirthsRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $dto = EmitBirthOrderDTO::fromArray([...$validated, 'issue' => true], $this->companyId(), $request->user()?->id);
            $result = ($this->useCases->register)($dto, BirthFieldData::mapFromAnimals($validated['animals']));
        } catch (Par01ValidationException $e) {
            return $this->sheetError($e);
        } catch (BirthOrderDomainException $e) {
            return $this->domainError($e);
        } catch (DomainException $e) {
            return response()->json(['status' => 'invalid', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => Par01ResultResource::message($result['result']),
            'order' => (new BirthOrderResource($result['order']))->resolve($request),
            'data' => (new Par01ResultResource($result['result']))->resolve($request),
        ], 201);
    }

    public function update(EmitBirthOrderRequest $request, int $id): JsonResponse
    {
        try {
            $dto = EmitBirthOrderDTO::fromArray($request->validated(), $this->companyId(), $request->user()?->id);
            $order = ($this->useCases->updateDraft)($id, $dto);
        } catch (BirthOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new BirthOrderResource($order));
    }

    public function issue(Request $request, int $id): JsonResponse
    {
        try {
            $order = ($this->useCases->issue)($id, $this->companyId(), $request->user()?->id);
        } catch (BirthOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new BirthOrderResource($order));
    }

    public function show(int $id): JsonResponse
    {
        $order = ($this->useCases->get)($id, $this->companyId());

        if ($order === null) {
            return response()->json(['message' => 'La orden de parición no existe.', 'code' => 'BIRTH_ORDER_NOT_FOUND'], 404);
        }

        return response()->json(new BirthOrderResource($order));
    }

    public function byCode(string $code): JsonResponse
    {
        $order = ($this->useCases->findByCode)($code, $this->companyId());

        if ($order === null) {
            return response()->json(['message' => "No existe la orden de parición {$code}.", 'code' => 'BIRTH_ORDER_NOT_FOUND'], 404);
        }

        return response()->json(new BirthOrderResource($order));
    }

    public function printed(Request $request, int $id): JsonResponse
    {
        try {
            $order = ($this->useCases->markPrinted)($id, $this->companyId(), $request->user()?->id);
        } catch (BirthOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new BirthOrderResource($order));
    }

    public function closeIncomplete(TransferOrderReasonRequest $request, int $id): JsonResponse
    {
        try {
            $order = ($this->useCases->closeIncomplete)($id, $this->companyId(), $request->user()?->id, (string) $request->validated('reason'));
        } catch (BirthOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new BirthOrderResource($order));
    }

    public function cancel(CancelTransferOrderRequest $request, int $id): JsonResponse
    {
        try {
            $order = ($this->useCases->cancel)($id, $this->companyId(), $request->user()?->id, $request->validated('reason'));
        } catch (BirthOrderDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(new BirthOrderResource($order));
    }

    /**
     * Registers one round from the screen. Answers with the same shape as the PAR-01 endpoint,
     * because it is the same round.
     */
    public function execute(ExecuteBirthOrderRequest $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = ($this->useCases->execute)(
                $id,
                $this->companyId(),
                $request->user()?->id,
                BirthFieldData::mapFromAnimals($validated['animals']),
                $validated['round_date'] ?? null
            );
        } catch (Par01ValidationException $e) {
            return $this->sheetError($e);
        } catch (BirthOrderDomainException $e) {
            return $this->domainError($e);
        } catch (DomainException $e) {
            return response()->json(['status' => 'invalid', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => Par01ResultResource::message($result),
            'data' => (new Par01ResultResource($result))->resolve($request),
        ], 201);
    }

    private function companyId(): int
    {
        return (int) $this->companyContext->getCompanyId();
    }

    private function sheetError(Par01ValidationException $e): JsonResponse
    {
        return response()->json([
            'status' => 'invalid',
            'message' => $e->getMessage(),
            'header_errors' => $e->getHeaderErrors(),
            'row_errors' => $e->getRowErrors(),
        ], 422);
    }

    private function domainError(BirthOrderDomainException $e): JsonResponse
    {
        $status = $e->getErrorCode() === 'BIRTH_ORDER_NOT_FOUND' ? 404 : 422;

        return response()->json(['message' => $e->getMessage(), 'code' => $e->getErrorCode()], $status);
    }
}
