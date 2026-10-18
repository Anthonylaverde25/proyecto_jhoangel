<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Stock\StockIncidentUseCases;
use App\Core\Enums\StockIncidentStatus;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\ResolveStockIncidentRequest;
use App\Http\Resources\StockIncidentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockIncidentController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(
        private readonly StockIncidentUseCases $incidents
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $itemId = $request->query('item_id');
        $incidents = ($this->incidents->list)(
            $this->activeCompanyId(),
            StockIncidentStatus::tryFrom((string) $request->query('status', '')),
            is_numeric($itemId) ? (int) $itemId : null,
        );

        return response()->json(StockIncidentResource::collection($incidents));
    }

    public function resolve(ResolveStockIncidentRequest $request, int $id): JsonResponse
    {
        $incident = ($this->incidents->resolve)($id, (string) $request->validated('resolution'), $this->activeCompanyId(), $request->user()?->id);

        return response()->json(new StockIncidentResource($incident));
    }
}
