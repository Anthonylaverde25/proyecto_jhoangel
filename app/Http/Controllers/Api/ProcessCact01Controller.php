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
use Illuminate\Http\JsonResponse;

final class ProcessCact01Controller extends Controller
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
            $dto = Cact01SubmissionDTO::fromArray($request->validated(), $companyId);
            $result = ($this->useCases->processCact01)($dto);
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

        $moved = array_sum(array_column($result['destinations'], 'count'));
        $destinationCount = count($result['destinations']);
        $where = $destinationCount === 1
            ? "al lote '{$result['destinations'][0]['batch_name']}'"
            : "a {$destinationCount} lotes";

        return response()->json([
            'status' => 'success',
            'message' => "Cambio de actividad registrado: {$moved} animales {$where}.",
            'data' => $result,
        ], 201);
    }
}
