<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Veterinary\SanitaryExposure\SanitaryExposureUseCases;
use App\Core\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SanitaryExposureAlertResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Exposure alerts: females that served with a bull found positive to a contagious pathogen.
 */
class SanitaryExposureAlertController extends Controller
{
    public function __construct(private readonly SanitaryExposureUseCases $useCases)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['status' => ['nullable', Rule::in(['OPEN', 'RESOLVED'])]]);

        $alerts = ($this->useCases->list)($this->companyId($request), $validated['status'] ?? null);

        return response()->json(SanitaryExposureAlertResource::collection($alerts));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $alert = ($this->useCases->show)($id, $this->companyId($request));
        if ($alert === null) {
            return response()->json(['message' => 'Novedad sanitaria no encontrada.'], 404);
        }

        return response()->json(new SanitaryExposureAlertResource($alert));
    }

    public function reviewFemales(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'female_caravan_ids' => 'required|array|min:1',
            'female_caravan_ids.*' => 'required|integer',
            'note' => 'nullable|string|max:255',
        ]);

        try {
            $reviewed = ($this->useCases->review)(
                $id,
                $this->companyId($request),
                array_map('intval', $validated['female_caravan_ids']),
                $request->user()?->id !== null ? (int) $request->user()->id : null,
                $validated['note'] ?? null
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['reviewed' => $reviewed]);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['resolution' => 'required|string|max:2000']);

        try {
            ($this->useCases->resolve)(
                $id,
                $this->companyId($request),
                $request->user()?->id !== null ? (int) $request->user()->id : null,
                (string) $validated['resolution']
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->show($request, $id);
    }

    /**
     * Open exposures of the given females, keyed by female id (pregnancy check grid).
     */
    public function openForFemales(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'female_caravan_ids' => 'required|array|max:2000',
            'female_caravan_ids.*' => 'required|integer',
        ]);

        return response()->json(($this->useCases->openForFemales)(
            array_map('intval', $validated['female_caravan_ids']),
            $this->companyId($request)
        ));
    }

    private function companyId(Request $request): int
    {
        return (int) $request->header('X-Company-ID');
    }
}
