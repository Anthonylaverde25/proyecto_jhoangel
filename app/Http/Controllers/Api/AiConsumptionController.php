<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Stock\PreviewAiConsumptionUseCase;
use App\Application\UseCases\Stock\RegisterAiConsumptionUseCase;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\PreviewAiConsumptionRequest;
use App\Http\Requests\Stock\StoreAiConsumptionRequest;
use App\Http\Resources\ItemDocumentResource;
use Illuminate\Http\JsonResponse;

/**
 * A consumption of one article to one batch, on behalf of an AI client: first what it would do, then
 * the option the user confirmed.
 */
class AiConsumptionController extends Controller
{
    use ResolvesActiveCompany;

    public function preview(PreviewAiConsumptionRequest $request, PreviewAiConsumptionUseCase $preview): JsonResponse
    {
        return response()->json(['data' => $preview($request->toDto(), $this->activeCompanyId())]);
    }

    /** 201 for a new consumption; 200 when its confirmation found one already recorded. */
    public function store(StoreAiConsumptionRequest $request, RegisterAiConsumptionUseCase $register): JsonResponse
    {
        $result = $register($request->toDto(), $this->activeCompanyId());

        return response()->json(['data' => ItemDocumentResource::fromLedger($result->ledger)->resolve($request) + [
            'transfers' => $result->transfers,
            'stock_after' => $result->stockAfter,
            'replayed' => $result->ledger->replayed,
        ]], $result->ledger->replayed ? 200 : 201);
    }
}
