<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\CaravanLookupResultDTO;
use App\Application\UseCases\Caravans\LookupCaravansUseCase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Caravans\LookupCaravansRequest;
use Illuminate\Http\JsonResponse;

class LookupCaravansController extends Controller
{
    public function __construct(
        private readonly LookupCaravansUseCase $lookupCaravans,
    ) {
    }

    /**
     * Classifies tags as not found, held by the active company, or held by another one.
     */
    public function __invoke(LookupCaravansRequest $request): JsonResponse
    {
        $results = ($this->lookupCaravans)($request->validated('identifications'));

        return response()->json([
            'data' => array_map(static fn (CaravanLookupResultDTO $r): array => $r->toArray(), $results),
        ]);
    }
}
