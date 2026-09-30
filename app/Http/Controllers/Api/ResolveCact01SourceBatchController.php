<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\WorkTemplates\WorkTemplateUseCases;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkTemplates\ResolveCact01SourceBatchRequest;
use Illuminate\Http\JsonResponse;

/**
 * The source batch the CACT-01 review screen proposes, from the name on the sheet and the
 * animals it lists. Finding nothing is an answer (`basis: none`), not an error.
 */
final class ResolveCact01SourceBatchController extends Controller
{
    public function __construct(
        private readonly WorkTemplateUseCases $useCases,
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function __invoke(ResolveCact01SourceBatchRequest $request): JsonResponse
    {
        if (!$this->companyContext->getCompanyId()) {
            return response()->json(['message' => 'No company context found'], 403);
        }

        $validated = $request->validated();

        $resolution = $this->useCases->resolveCact01SourceBatch->execute(
            $validated['lote_origen'] ?? null,
            array_map(static fn ($tag): string => (string) $tag, $validated['caravanas'] ?? []),
        );

        return response()->json(['data' => $resolution->toArray()]);
    }
}
