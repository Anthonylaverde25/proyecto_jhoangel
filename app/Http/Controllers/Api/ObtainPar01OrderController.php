<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Par01\Par01SubmissionDTO;
use App\Application\UseCases\WorkTemplates\WorkTemplateUseCases;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\Par01ValidationException;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkTemplates\ProcessPar01Request;
use App\Http\Resources\BirthOrderResource;
use Illuminate\Http\JsonResponse;

/**
 * "Obtener orden de parición" on the PAR-01 review: the same payload as confirming the sheet,
 * answered with the order generated from it instead of the calvings.
 */
final class ObtainPar01OrderController extends Controller
{
    public function __construct(
        private readonly WorkTemplateUseCases $useCases,
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function __invoke(ProcessPar01Request $request): JsonResponse
    {
        $companyId = $this->companyContext->getCompanyId();

        if (!$companyId) {
            return response()->json(['message' => 'No company context found'], 403);
        }

        try {
            $dto = Par01SubmissionDTO::fromArray($request->validated(), $companyId, $request->user()?->id);
            $order = $this->useCases->processPar01->obtainOrder($dto);
        } catch (Par01ValidationException $e) {
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
            'data' => (new BirthOrderResource($order))->resolve($request),
        ], 201);
    }
}
