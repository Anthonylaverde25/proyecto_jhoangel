<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Caravans\RegisterDeathDTO;
use App\Application\Services\DeathBatchResolver;
use App\Application\UseCases\Caravans\RegisterDeathUseCase;
use App\Core\Entities\BatchEntity;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Interfaces\IDeathCauseRepository;
use App\Http\Controllers\Controller;
use App\Http\Requests\Caravans\RegisterDeathRequest;
use App\Http\Resources\CaravanResource;
use Illuminate\Http\JsonResponse;

/**
 * "Registrar muerte": the causes to choose from, the death batches the animal may go to, and the
 * registration itself.
 */
class DeathController extends Controller
{
    public function __construct(
        private readonly IDeathCauseRepository $causes,
        private readonly DeathBatchResolver $deathBatches,
        private readonly RegisterDeathUseCase $registerDeath,
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function causes(): JsonResponse
    {
        return response()->json(['data' => $this->causes->findActive()]);
    }

    public function deathBatches(): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (BatchEntity $batch) => [
                'id' => $batch->getId(),
                'name' => $batch->getName(),
            ], $this->deathBatches->available()),
        ]);
    }

    public function store(RegisterDeathRequest $request): JsonResponse
    {
        $data = $request->validated();

        $dead = ($this->registerDeath)(new RegisterDeathDTO(
            caravanIds: array_map('intval', $data['caravan_ids']),
            deathDate: $data['death_date'],
            deathCauseId: (int) $data['death_cause_id'],
            observations: isset($data['observations']) && trim((string) $data['observations']) !== '' ? trim((string) $data['observations']) : null,
            deathBatchId: isset($data['death_batch_id']) ? (int) $data['death_batch_id'] : null,
            companyId: (int) $this->companyContext->getCompanyId(),
            userId: $request->user()?->id !== null ? (int) $request->user()->id : null
        ));

        return response()->json([
            'message' => count($dead) === 1 ? 'Muerte registrada.' : count($dead) . ' muertes registradas.',
            'data' => CaravanResource::collection($dead),
        ], 201);
    }
}
