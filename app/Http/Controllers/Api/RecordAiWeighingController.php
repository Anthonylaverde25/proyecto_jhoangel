<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Caravans\RecordAiWeighingUseCase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Caravans\RecordAiWeighingRequest;
use Illuminate\Http\JsonResponse;

class RecordAiWeighingController extends Controller
{
    public function __construct(
        private readonly RecordAiWeighingUseCase $recordAiWeighing,
    ) {
    }

    /** One control weighing of one caravan of the active company, on behalf of an AI client. */
    public function __invoke(RecordAiWeighingRequest $request, int $id): JsonResponse
    {
        $result = ($this->recordAiWeighing)($request->toDto($id));

        return response()->json(['data' => $result->toArray()], 201);
    }
}
