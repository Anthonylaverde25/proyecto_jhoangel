<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Caravans\CountHerdUseCase;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * The herd at a glance. Only the head count for now; breakdowns can be added to `data` later
 * without changing the route.
 */
final class HerdSummaryController extends Controller
{
    public function __construct(
        private readonly CountHerdUseCase $countHerd
    ) {
    }

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'total' => ($this->countHerd)(),
                'updated_at' => Carbon::now()->toIso8601String(),
            ],
        ]);
    }
}
