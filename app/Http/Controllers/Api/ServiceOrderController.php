<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\ServiceOrders\CreateServiceOrderDTO;
use App\Application\UseCases\ServiceOrders\CreateServiceOrderUseCase;
use App\Application\UseCases\ServiceOrders\ApproveServiceOrderUseCase;
use App\Application\UseCases\ServiceOrders\CloseServiceOrderDiagnosisUseCase;
use App\Application\UseCases\ServiceOrders\GetServiceOrderBullHistoryUseCase;
use App\Application\UseCases\ServiceOrders\GetServiceOrderDiagnosisUseCase;
use App\Application\UseCases\ServiceOrders\MarkFemalesNotDiagnosedUseCase;
use App\Application\UseCases\ServiceOrders\ResolveServiceOrderIncidentUseCase;
use App\Application\UseCases\ServiceOrders\CloseServiceOrderWithBullWithdrawalUseCase;
use App\Application\UseCases\ServiceOrders\UpdateServiceOrderStatusUseCase;
use App\Application\UseCases\ServiceOrders\GetServiceOrderUseCase;
use App\Application\UseCases\ServiceOrders\ListServiceOrdersUseCase;
use App\Application\UseCases\ServiceOrders\ReplaceServiceBullUseCase;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Enums\ServiceOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateServiceOrderRequest;
use App\Http\Requests\ReviewServiceOrderRequest;
use App\Http\Requests\ServiceOrders\CloseServiceOrderDiagnosisRequest;
use App\Http\Requests\ServiceOrders\MarkNotDiagnosedRequest;
use App\Http\Requests\ServiceOrders\ResolveServiceOrderIncidentRequest;
use App\Http\Requests\ServiceOrders\CloseServiceOrderWithBullWithdrawalRequest;
use App\Http\Requests\ServiceOrders\ReplaceServiceBullRequest;
use App\Http\Requests\ServiceOrders\UpdateServiceOrderStatusRequest;
use App\Http\Requests\ServiceOrders\UploadServiceOrderPdfRequest;
use App\Http\Resources\ServiceOrderBullReplacementResource;
use App\Http\Resources\ServiceOrderResource;
use App\Models\ServiceOrderBullReplacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ServiceOrderController extends Controller
{
    public function __construct(
        private readonly CreateServiceOrderUseCase $createUseCase,
        private readonly ApproveServiceOrderUseCase $approveUseCase,
        private readonly CloseServiceOrderDiagnosisUseCase $closeDiagnosisUseCase,
        private readonly GetServiceOrderDiagnosisUseCase $getDiagnosisUseCase,
        private readonly MarkFemalesNotDiagnosedUseCase $markNotDiagnosedUseCase,
        private readonly ResolveServiceOrderIncidentUseCase $resolveIncidentUseCase,
        private readonly CloseServiceOrderWithBullWithdrawalUseCase $closeServiceUseCase,
        private readonly UpdateServiceOrderStatusUseCase $updateStatusUseCase,
        private readonly GetServiceOrderUseCase $getUseCase,
        private readonly ListServiceOrdersUseCase $listUseCase,
        private readonly ReplaceServiceBullUseCase $replaceBullUseCase,
        private readonly \App\Application\UseCases\ServiceOrders\RetireServiceBullUseCase $retireBullUseCase
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

    /**
     * "Cerrar orden": the missing verdicts, the decision on the bulls, and the closing, at once.
     */
    public function closeDiagnosis(CloseServiceOrderDiagnosisRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');

        try {
            $result = ($this->closeDiagnosisUseCase)($request->toDTO($id, $companyId, Auth::id() !== null ? (int) Auth::id() : null));

            return response()->json([
                'message' => 'Orden de servicio cerrada con su diagnóstico.',
                'order' => new ServiceOrderResource($result['order']),
                'continuation_order' => $result['continuation_order'] !== null
                    ? ['id' => $result['continuation_order']->getId(), 'code' => $result['continuation_order']->getCode()]
                    : null,
                'transfer_order' => $result['transfer_order'] !== null
                    ? ['id' => $result['transfer_order']->getId(), 'code' => $result['transfer_order']->getCode()]
                    : null,
                'moved_female_ids' => $result['moved_female_ids'],
            ]);
        } catch (ServiceOrderDomainException $e) {
            return $this->domainError($e);
        }
    }

    /**
     * Rounds, verdicts of every female and incidents of the order.
     */
    public function diagnosis(Request $request, int $id): JsonResponse
    {
        try {
            return response()->json(($this->getDiagnosisUseCase)($id, (int) $request->header('X-Company-ID')));
        } catch (ServiceOrderDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * Every bull of the order: how and why he joined and left, and the gestations he is the
     * confirmed or a candidate sire of (assistant and MCP).
     */
    public function bullHistory(Request $request, int $id, GetServiceOrderBullHistoryUseCase $useCase): JsonResponse
    {
        try {
            return response()->json($useCase($id, (int) $request->header('X-Company-ID')));
        } catch (ServiceOrderDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * "Guardar avance": females declared without diagnosis, once the bulls are gone.
     */
    public function markNotDiagnosed(MarkNotDiagnosedRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');

        try {
            $count = ($this->markNotDiagnosedUseCase)($request->toDTO($id, $companyId, Auth::id() !== null ? (int) Auth::id() : null));

            return response()->json(['message' => "{$count} vientre(s) declarados sin diagnóstico.", 'count' => $count], 201);
        } catch (ServiceOrderDomainException $e) {
            return $this->domainError($e);
        }
    }

    public function resolveIncident(ResolveServiceOrderIncidentRequest $request, int $id, int $incidentId): JsonResponse
    {
        try {
            ($this->resolveIncidentUseCase)(
                $id,
                $incidentId,
                (int) $request->header('X-Company-ID'),
                (string) $request->validated('resolution'),
                Auth::id() !== null ? (int) Auth::id() : null
            );

            return response()->json(['message' => 'Novedad resuelta.']);
        } catch (ServiceOrderDomainException $e) {
            return $this->domainError($e);
        }
    }

    private function domainError(ServiceOrderDomainException $e): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $e->getMessage(),
            'code' => $e->getReasonCode(),
        ] + $e->getContext(), static fn ($value) => $value !== null), 422);
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

    public function replaceBull(ReplaceServiceBullRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');
        $userId = (int) Auth::id();

        try {
            $dto = $request->toDTO($id, $companyId, $userId);
            $replacement = ($this->replaceBullUseCase)($dto);

            return response()->json([
                'message'     => 'Sustitución de reproductor registrada exitosamente',
                'replacement' => new ServiceOrderBullReplacementResource($replacement),
            ], 201);
        } catch (\App\Core\Exceptions\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Take a bull out of a running service without a replacement.
     */
    public function retireBull(\App\Http\Requests\ServiceOrders\RetireServiceBullRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');
        $userId = (int) Auth::id();

        try {
            return response()->json(($this->retireBullUseCase)($request->toDTO($id, $companyId, $userId)), 200);
        } catch (\App\Core\Exceptions\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function getBullReplacements(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');

        $replacements = ServiceOrderBullReplacement::where('service_order_id', $id)
            ->where('company_id', $companyId)
            ->with([
                'retiredMaleCaravan.currentWeight',
                'replacementMaleCaravan.currentWeight',
                'replacementMaleCaravan.bullHealthEvaluation',
                'destinationBatch',
                'user',
            ])
            ->orderByDesc('replacement_date')
            ->get();

        return response()->json(ServiceOrderBullReplacementResource::collection($replacements));
    }

    public function closeService(CloseServiceOrderWithBullWithdrawalRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');
        $userId = (int) Auth::id();

        try {
            $dto = $request->toDTO($id, $companyId, $userId);
            $result = ($this->closeServiceUseCase)($dto);
            $reopened = count($result['reopened_female_ids']);

            return response()->json([
                'message' => 'Torada retirada. La orden sigue abierta hasta completar el diagnóstico de gestación.'
                    . ($reopened > 0 ? " {$reopened} vacía(s) tactada(s) con los toros en el lote vuelven a pendiente." : ''),
                'order'   => new ServiceOrderResource($result['order']),
                'reopened_female_ids' => $result['reopened_female_ids'],
            ]);
        } catch (ServiceOrderDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}

