<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Dest01\Dest01SubmissionDTO;
use App\Application\UseCases\WorkTemplates\WorkTemplateUseCases;
use App\Core\Exceptions\Dest01ValidationException;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkTemplates\ProcessDest01Request;
use App\Http\Resources\Dest01ResultResource;
use Illuminate\Http\JsonResponse;

final class ProcessDest01Controller extends Controller
{
    public function __construct(
        private readonly WorkTemplateUseCases $useCases,
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function __invoke(ProcessDest01Request $request): JsonResponse
    {
        $companyId = $this->companyContext->getCompanyId();

        if (!$companyId) {
            return response()->json(['message' => 'No company context found'], 403);
        }

        try {
            $dto = Dest01SubmissionDTO::fromArray($request->validated(), $companyId, $request->user()?->id);
            $result = ($this->useCases->processDest01)($dto);
        } catch (Dest01ValidationException $e) {
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
            'message' => Dest01ResultResource::message($result),
            'data' => (new Dest01ResultResource($result))->resolve($request),
        ], 201);
    }
}
