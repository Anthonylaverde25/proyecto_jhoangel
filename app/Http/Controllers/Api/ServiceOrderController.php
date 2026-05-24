<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\ServiceOrders\CreateServiceOrderDTO;
use App\Application\UseCases\ServiceOrders\CreateServiceOrderUseCase;
use App\Application\UseCases\ServiceOrders\SubmitServiceOrderReviewUseCase;
use App\Application\UseCases\ServiceOrders\ReviewServiceOrderUseCase;
use App\Application\UseCases\ServiceOrders\ApproveServiceOrderUseCase;
use App\Application\UseCases\ServiceOrders\ExecuteServiceOrderUseCase;
use App\Application\UseCases\ServiceOrders\CompleteServiceOrderUseCase;
use App\Application\UseCases\ServiceOrders\GetServiceOrderUseCase;
use App\Application\UseCases\ServiceOrders\ListServiceOrdersUseCase;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateServiceOrderRequest;
use App\Http\Requests\ReviewServiceOrderRequest;
use App\Http\Resources\ServiceOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ServiceOrderController extends Controller
{
    public function __construct(
        private readonly CreateServiceOrderUseCase $createUseCase,
        private readonly SubmitServiceOrderReviewUseCase $submitReviewUseCase,
        private readonly ReviewServiceOrderUseCase $reviewUseCase,
        private readonly ApproveServiceOrderUseCase $approveUseCase,
        private readonly ExecuteServiceOrderUseCase $executeUseCase,
        private readonly CompleteServiceOrderUseCase $completeUseCase,
        private readonly GetServiceOrderUseCase $getUseCase,
        private readonly ListServiceOrdersUseCase $listUseCase
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');
        $entities = ($this->listUseCase)($companyId);

        return response()->json(ServiceOrderResource::collection($entities));
    }

    public function store(CreateServiceOrderRequest $request): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');
        $userId = (int) Auth::id();

        $data = $request->validated();
        $data['company_id'] = $companyId;
        $data['requested_by_user_id'] = $userId;

        try {
            $dto = CreateServiceOrderDTO::fromArray($data);
            $entity = ($this->createUseCase)($dto);

            return response()->json(new ServiceOrderResource($entity), 201);
        } catch (ServiceOrderDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');
        $entity = ($this->getUseCase)($id, $companyId);

        if ($entity === null) {
            return response()->json(['message' => 'Service order not found'], 404);
        }

        return response()->json(new ServiceOrderResource($entity));
    }

    public function submitReview(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');
        $userId = (int) Auth::id();

        try {
            $entity = ($this->submitReviewUseCase)($id, $companyId, $userId);
            return response()->json(new ServiceOrderResource($entity));
        } catch (ServiceOrderDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function review(ReviewServiceOrderRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');
        $userId = (int) Auth::id();
        $approve = (bool) $request->input('approve');
        $reason = $request->input('reason');

        try {
            $entity = ($this->reviewUseCase)($id, $companyId, $userId, $approve, $reason);
            return response()->json(new ServiceOrderResource($entity));
        } catch (ServiceOrderDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function approve(ReviewServiceOrderRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');
        $userId = (int) Auth::id();
        $approve = (bool) $request->input('approve');
        $reason = $request->input('reason');

        try {
            $entity = ($this->approveUseCase)($id, $companyId, $userId, $approve, $reason);
            return response()->json(new ServiceOrderResource($entity));
        } catch (ServiceOrderDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function execute(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');
        $userId = (int) Auth::id();

        try {
            $entity = ($this->executeUseCase)($id, $companyId, $userId);
            return response()->json(new ServiceOrderResource($entity));
        } catch (ServiceOrderDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');
        $userId = (int) Auth::id();
        $observations = $request->input('observations');

        try {
            $entity = ($this->completeUseCase)($id, $companyId, $userId, $observations);
            return response()->json(new ServiceOrderResource($entity));
        } catch (ServiceOrderDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function uploadPdf(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:10240',
        ]);

        $file = $request->file('pdf');
        $fileName = 'SO-' . $id . '-' . time() . '.pdf';
        
        $destinationPath = public_path('pdfs');
        if (!file_exists($destinationPath)) {
            mkdir($destinationPath, 0755, true);
        }
        
        $file->move($destinationPath, $fileName);
        $url = url('pdfs/' . $fileName);

        return response()->json(['url' => $url]);
    }
}
