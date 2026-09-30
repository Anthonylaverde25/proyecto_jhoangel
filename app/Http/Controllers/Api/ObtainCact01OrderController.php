<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Cact01\Cact01SubmissionDTO;
use App\Application\UseCases\WorkTemplates\WorkTemplateUseCases;
use App\Core\Exceptions\Cact01ValidationException;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkTemplates\ProcessCact01Request;
use App\Http\Resources\TransferOrderResource;
use Illuminate\Http\JsonResponse;

/**
 * "Obtener orden de transferencia" on the CACT-01 review: the same payload as confirming the
 * sheet, answered with the order generated from it instead of the movement.
 */
final class ObtainCact01OrderController extends Controller
{
    public function __construct(
        private readonly WorkTemplateUseCases $useCases,
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function __invoke(ProcessCact01Request $request): JsonResponse
    {
        $companyId = $this->companyContext->getCompanyId();

        if (!$companyId) {
            return response()->json(['message' => 'No company context found'], 403);
        }

        try {
            $dto = Cact01SubmissionDTO::fromArray($request->validated(), $companyId, $request->user('sanctum')?->id);
            $order = $this->useCases->processCact01->obtainOrder($dto);
        } catch (Cact01ValidationException $e) {
            return response()->json([
                'status' => 'invalid',
                'message' => $e->getMessage(),
                'header_errors' => $e->getHeaderErrors(),
                'row_errors' => $e->getRowErrors(),
            ], 422);
        } catch (DomainException $e) {
            return response()->json([
                'status' => 'invalid',
                'message' => $e->getMessage(),
                'header_errors' => [],
                'row_errors' => [],
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => "Se generó la orden {$order->getCode()}.",
            'data' => (new TransferOrderResource($order))->resolve($request),
        ], 201);
    }
}
