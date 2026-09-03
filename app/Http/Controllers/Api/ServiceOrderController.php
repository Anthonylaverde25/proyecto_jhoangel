<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\ServiceOrders\CreateServiceOrderDTO;
use App\Application\UseCases\ServiceOrders\CreateServiceOrderUseCase;
use App\Application\UseCases\ServiceOrders\ApproveServiceOrderUseCase;
use App\Application\UseCases\ServiceOrders\CompleteServiceOrderUseCase;
use App\Application\UseCases\ServiceOrders\UpdateServiceOrderStatusUseCase;
use App\Application\UseCases\ServiceOrders\GetServiceOrderUseCase;
use App\Application\UseCases\ServiceOrders\ListServiceOrdersUseCase;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Enums\ServiceOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateServiceOrderRequest;
use App\Http\Requests\ReviewServiceOrderRequest;
use App\Http\Requests\ServiceOrders\CompleteServiceOrderRequest;
use App\Http\Requests\ServiceOrders\UpdateServiceOrderStatusRequest;
use App\Http\Requests\ServiceOrders\UploadServiceOrderPdfRequest;
use App\Http\Resources\ServiceOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ServiceOrderController extends Controller
{
    public function __construct(
        private readonly CreateServiceOrderUseCase $createUseCase,
        private readonly ApproveServiceOrderUseCase $approveUseCase,
        private readonly CompleteServiceOrderUseCase $completeUseCase,
        private readonly UpdateServiceOrderStatusUseCase $updateStatusUseCase,
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
        } catch (\App\Core\Exceptions\DomainException $e) {
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

    public function complete(CompleteServiceOrderRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');
        $userId = (int) Auth::id();
        $validated = $request->validated();
        $observations = $validated['observations'] ?? null;
        $targetBatchId = isset($validated['target_batch_id']) ? (int) $validated['target_batch_id'] : null;

        try {
            $entity = ($this->completeUseCase)($id, $companyId, $userId, $observations, $targetBatchId);
            return response()->json(new ServiceOrderResource($entity));
        } catch (ServiceOrderDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function updateStatus(UpdateServiceOrderStatusRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');
        $userId = (int) Auth::id();
        $validated = $request->validated();
        $statusStr = $validated['status'];

        $newStatus = ServiceOrderStatus::tryFrom(strtoupper($statusStr));
        if ($newStatus === null) {
            return response()->json(['message' => 'Invalid status provided'], 422);
        }

        try {
            $entity = ($this->updateStatusUseCase)($id, $companyId, $userId, $newStatus);
            return response()->json(new ServiceOrderResource($entity));
        } catch (ServiceOrderDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function uploadPdf(UploadServiceOrderPdfRequest $request, int $id): JsonResponse
    {
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
