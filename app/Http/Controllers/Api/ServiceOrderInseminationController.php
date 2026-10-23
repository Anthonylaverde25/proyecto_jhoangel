<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Inseminations\RegisterInseminationsDTO;
use App\Application\UseCases\ServiceOrders\InseminationUseCases;
use App\Core\Entities\ItemDocumentEntity;
use App\Core\Entities\StockIncidentEntity;
use App\Core\ValueObjects\InseminationFilters;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inseminations\AddRepasoBullsRequest;
use App\Http\Requests\Inseminations\EndInseminationServiceRequest;
use App\Http\Requests\Inseminations\RegisterInseminationsRequest;
use App\Http\Requests\Inseminations\VoidInseminationRequest;
use App\Http\Requests\ServiceOrders\CloseServiceOrderDiagnosisRequest;
use App\Http\Resources\ItemDocumentResource;
use App\Http\Resources\ServiceOrderResource;
use App\Http\Resources\StockIncidentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The inseminations of an AI service order (semen S-B): load, void, end the service, repaso bulls. */
class ServiceOrderInseminationController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(
        private readonly InseminationUseCases $inseminations
    ) {
    }

    public function index(int $id): JsonResponse
    {
        return response()->json($this->inseminations->inseminations->listForOrder($id, $this->activeCompanyId()));
    }

    public function store(RegisterInseminationsRequest $request, int $id): JsonResponse
    {
        $result = ($this->inseminations->register)(
            RegisterInseminationsDTO::fromArray($id, $this->activeCompanyId(), $request->user()?->id, $request->validated())
        );

        return response()->json([
            'order' => new ServiceOrderResource($result['order']),
            'documents' => array_map(
                fn (ItemDocumentEntity $document) => (new ItemDocumentResource($document))->resolve($request),
                $result['documents']
            ),
            'incidents' => array_map(fn (StockIncidentEntity $i) => StockIncidentResource::shape($i), $result['incidents']),
        ], $result['replayed'] ? 200 : 201);
    }

    public function void(VoidInseminationRequest $request, int $id, int $inseminationId): JsonResponse
    {
        $order = ($this->inseminations->void)(
            $id,
            $inseminationId,
            (string) $request->validated('reason'),
            (string) $request->validated('idempotency_key'),
            $this->activeCompanyId(),
            $request->user()?->id
        );

        return response()->json(new ServiceOrderResource($order));
    }

    public function endService(EndInseminationServiceRequest $request, int $id): JsonResponse
    {
        $order = ($this->inseminations->endService)(
            $id,
            $this->activeCompanyId(),
            (int) $request->user()?->id,
            (string) $request->validated('end_date'),
            CloseServiceOrderDiagnosisRequest::notInseminatedReasons((array) $request->validated('not_inseminated_reasons', [])),
            $request->validated('observations')
        );

        return response()->json(new ServiceOrderResource($order));
    }

    public function addRepasoBulls(AddRepasoBullsRequest $request, int $id): JsonResponse
    {
        $order = ($this->inseminations->addRepasoBulls)(
            $id,
            $this->activeCompanyId(),
            (int) $request->user()?->id,
            array_map('intval', (array) $request->validated('male_caravan_ids')),
            (string) $request->validated('joined_at')
        );

        return response()->json(new ServiceOrderResource($order));
    }

    /** Inseminations of every AI order, filtered (assistant and MCP read it). */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'item_id' => 'nullable|integer',
            'semen_sire_id' => 'nullable|integer',
            'semen_lot_id' => 'nullable|integer',
            'service_order_id' => 'nullable|integer',
            'batch_id' => 'nullable|integer',
            'caravan' => 'nullable|string|max:50',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'include_voided' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        return response()->json(($this->inseminations->list)(InseminationFilters::fromArray($request->query()), $this->activeCompanyId()));
    }

    public function coverage(Request $request): JsonResponse
    {
        $request->validate([
            'item_ids' => 'nullable|array',
            'item_ids.*' => 'integer',
            'extra' => 'nullable|array',
            'extra.*' => 'integer|min:0',
        ]);
        $extra = [];
        foreach ((array) $request->input('extra', []) as $itemId => $straws) {
            $extra[(int) $itemId] = (int) $straws;
        }

        return response()->json(($this->inseminations->coverage)(
            array_map('intval', (array) $request->input('item_ids', [])),
            $extra,
            $this->activeCompanyId()
        ));
    }
}
