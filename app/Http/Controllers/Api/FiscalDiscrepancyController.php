<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\DeliveryNotes\DeliveryNoteUseCases;
use App\Core\ValueObjects\DiscrepancyFilter;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeliveryNotes\ResolveDiscrepancyRequest;
use App\Http\Resources\FiscalDiscrepancyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Differences between delivery notes and their invoices, closed by hand (delivery C, DC7 and DC8). */
class FiscalDiscrepancyController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(
        private readonly DeliveryNoteUseCases $deliveryNotes
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $discrepancies = ($this->deliveryNotes->discrepancies)(DiscrepancyFilter::fromArray($request->query()), $this->activeCompanyId());

        return response()->json(FiscalDiscrepancyResource::collection($discrepancies));
    }

    public function resolve(ResolveDiscrepancyRequest $request, int $id): JsonResponse
    {
        $discrepancy = ($this->deliveryNotes->resolveDiscrepancy)($id, (string) $request->validated('resolution'), $this->activeCompanyId(), (int) $request->user()?->id);

        return response()->json(new FiscalDiscrepancyResource($discrepancy));
    }
}
