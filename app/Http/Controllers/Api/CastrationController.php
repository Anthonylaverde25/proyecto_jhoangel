<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\RegisterCastrationDTO;
use App\Application\UseCases\Castrations\ListCastrationCandidatesUseCase;
use App\Application\UseCases\Castrations\RegisterCastrationUseCase;
use App\Application\UseCases\Castrations\VoidCastrationUseCase;
use App\Core\Enums\CastrationMethod;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterCastrationRequest;
use App\Http\Requests\VoidCastrationRequest;
use App\Http\Resources\CastrationCandidateResource;
use App\Http\Resources\CastrationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CastrationController extends Controller
{
    public function __construct(
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function candidates(Request $request, int $batchId, ListCastrationCandidatesUseCase $useCase): JsonResponse
    {
        $companyId = (int) $this->companyContext->getCompanyId();
        $result = $useCase($companyId, $batchId);

        return response()->json([
            'data' => CastrationCandidateResource::collection($result['candidates']),
            'steer_age_limit_months' => $result['steer_age_limit_months'],
        ]);
    }

    public function store(RegisterCastrationRequest $request, RegisterCastrationUseCase $useCase): JsonResponse
    {
        $companyId = (int) $this->companyContext->getCompanyId();
        $userId = $request->user()?->id ? (int) $request->user()->id : null;

        $methodRaw = $request->validated('method');
        $method = $methodRaw !== null ? CastrationMethod::tryFrom($methodRaw) : null;

        $dto = new RegisterCastrationDTO(
            companyId: $companyId,
            userId: $userId,
            castratedOn: (string) $request->validated('castrated_on'),
            method: $method,
            notes: $request->validated('notes'),
            items: $request->validated('items')
        );

        $results = $useCase($dto);

        return response()->json(CastrationResource::collection($results), 201);
    }

    public function void(VoidCastrationRequest $request, int $id, VoidCastrationUseCase $useCase): JsonResponse
    {
        $companyId = (int) $this->companyContext->getCompanyId();
        $userId = $request->user()?->id ? (int) $request->user()->id : null;

        $result = $useCase(
            companyId: $companyId,
            castrationId: $id,
            reason: $request->validated('reason'),
            userId: $userId
        );

        return response()->json(new CastrationResource($result), 200);
    }
}

