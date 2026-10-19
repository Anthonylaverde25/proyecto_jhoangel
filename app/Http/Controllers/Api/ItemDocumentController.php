<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Stock\StockCountDTO;
use App\Application\DTOs\Stock\StockOperationDTO;
use App\Application\DTOs\Stock\StockTransferDTO;
use App\Application\Services\Stock\LedgerResult;
use App\Application\UseCases\Stock\ItemDocumentUseCases;
use App\Core\Entities\ItemDocumentEntity;
use App\Core\ValueObjects\ItemDocumentFilters;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\ReasonRequest;
use App\Http\Requests\Stock\StoreConsumptionRequest;
use App\Http\Requests\Stock\StoreInitialStockRequest;
use App\Http\Requests\Stock\StoreStockCountRequest;
use App\Http\Requests\Stock\StoreStockTransferRequest;
use App\Http\Resources\ItemDocumentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Stock operations. Append-only: there is no update or delete; a mistake is reversed.
 */
class ItemDocumentController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(
        private readonly ItemDocumentUseCases $documents
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = ItemDocumentFilters::fromArray($request->query());
        $page = ($this->documents->search)($filters, $this->activeCompanyId());

        return response()->json([
            'data' => array_map(fn (ItemDocumentEntity $d) => (new ItemDocumentResource($d))->resolve($request), $page['data']),
            'total' => $page['total'],
            'page' => $filters->page,
            'per_page' => $filters->perPage,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json((new ItemDocumentResource(($this->documents->find)($id, $this->activeCompanyId())))->resolve($request));
    }

    public function storeInitialStock(StoreInitialStockRequest $request): JsonResponse
    {
        $result = ($this->documents->registerInitialStock)(StockOperationDTO::fromArray($request->validated(), $request->user()?->id), $this->activeCompanyId());

        return $this->created($request, $result);
    }

    public function storeConsumption(StoreConsumptionRequest $request): JsonResponse
    {
        $result = ($this->documents->registerConsumption)(StockOperationDTO::fromArray($request->validated(), $request->user()?->id), $this->activeCompanyId());

        return $this->created($request, $result);
    }

    public function storeCount(StoreStockCountRequest $request): JsonResponse
    {
        $result = ($this->documents->registerCount)(StockCountDTO::fromArray($request->validated(), $request->user()?->id), $this->activeCompanyId());

        return $this->created($request, $result);
    }

    public function storeTransfer(StoreStockTransferRequest $request): JsonResponse
    {
        $result = ($this->documents->registerTransfer)(StockTransferDTO::fromArray($request->validated(), $request->user()?->id), $this->activeCompanyId());

        return $this->created($request, $result);
    }

    public function reverse(ReasonRequest $request, int $id): JsonResponse
    {
        $result = ($this->documents->reverse)(
            $id,
            (string) $request->validated('reason'),
            (string) ($request->validated('idempotency_key') ?? Str::uuid()),
            $this->activeCompanyId(),
            $request->user()?->id,
        );

        return $this->created($request, $result);
    }

    /** 201 for a new operation; 200 when its idempotency key found one already recorded (DS8). */
    private function created(Request $request, LedgerResult $result): JsonResponse
    {
        return response()->json(ItemDocumentResource::fromLedger($result)->resolve($request), $result->replayed ? 200 : 201);
    }
}
