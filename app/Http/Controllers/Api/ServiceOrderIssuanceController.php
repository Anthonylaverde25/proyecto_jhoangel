<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Services\PendingTactoFemales;
use App\Application\DTOs\ServiceOrders\IssueServiceOrdersDTO;
use App\Application\DTOs\ServiceOrders\RegisterServiceOrdersDTO;
use App\Application\UseCases\ServiceOrders\ServiceOrderIssuanceUseCases;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceOrders\IssueServiceOrdersRequest;
use App\Http\Requests\ServiceOrders\RegisterServiceOrdersRequest;
use App\Http\Requests\ServiceOrders\UpdatePlannedServiceOrderRequest;
use App\Http\Resources\ServiceOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** How a service order comes to exist: started or planned over its batches, or registered afterwards. */
class ServiceOrderIssuanceController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(
        private readonly ServiceOrderIssuanceUseCases $useCases,
        private readonly IServiceOrderRepository $orders
    ) {
    }

    public function issue(IssueServiceOrdersRequest $request): JsonResponse
    {
        $result = ($this->useCases->issue)(
            IssueServiceOrdersDTO::fromArray($request->validated(), $this->activeCompanyId(), (int) $request->user()->id)
        );

        return response()->json([
            'orders' => ServiceOrderResource::collection($result['orders']),
            'planned' => $result['planned'],
            'warnings' => $result['warnings'],
        ], 201);
    }

    public function register(RegisterServiceOrdersRequest $request): JsonResponse
    {
        $orders = ($this->useCases->register)(
            RegisterServiceOrdersDTO::fromArray($request->validated(), $this->activeCompanyId(), (int) $request->user()->id)
        );

        return response()->json(['orders' => ServiceOrderResource::collection($orders)], 201);
    }

    public function startPlanned(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['start_date' => 'required|date']);
        $result = ($this->useCases->startPlanned)($id, $this->activeCompanyId(), (int) $request->user()->id, $validated['start_date']);

        return response()->json(['order' => new ServiceOrderResource($result['order']), 'warnings' => $result['warnings']]);
    }

    public function updatePlanned(UpdatePlannedServiceOrderRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();
        $result = ($this->useCases->updatePlanned)(
            $id,
            $this->activeCompanyId(),
            (int) $request->user()->id,
            $validated['start_date'],
            $validated['end_date'] ?? null,
            $validated['observations'] ?? null,
            array_map('intval', $validated['male_caravan_ids'] ?? [])
        );

        return response()->json(['order' => new ServiceOrderResource($result['order']), 'warnings' => $result['warnings']]);
    }

    /** Females that owe their tacto to an open order, so the sheet can warn before starting another. */
    public function pendingTactoFemales(PendingTactoFemales $pendingTacto): JsonResponse
    {
        $females = [];
        foreach ($pendingTacto->of(null, $this->activeCompanyId()) as $femaleId => $order) {
            $females[] = ['caravan_id' => $femaleId, ...$order];
        }

        return response()->json(['females' => $females]);
    }

    /** Where these bulls were on a day, to fill in the trip of a registered service. */
    public function bullLocations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'caravan_ids'   => 'required|array|min:1',
            'caravan_ids.*' => 'integer',
            'on_day'        => 'required|date',
            'moved_after'   => 'required|date',
        ]);

        return response()->json(['locations' => $this->orders->findBullLocations(
            array_map('intval', $validated['caravan_ids']),
            $this->activeCompanyId(),
            substr($validated['on_day'], 0, 10),
            substr($validated['moved_after'], 0, 10)
        )]);
    }
}
