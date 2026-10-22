<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\WeaningDeviations\ListWeaningDeviationsUseCase;
use App\Application\UseCases\WeaningDeviations\ReviewWeaningDeviationsUseCase;
use App\Core\Enums\WeaningDeviationKind;
use App\Core\Exceptions\WeaningOrderDomainException;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\WeaningDeviations\ReviewWeaningDeviationsRequest;
use App\Http\Resources\WeaningDeviationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The deviations of weaning orders from their plan: listed for the audit tab, and reviewed.
 */
final class WeaningDeviationController extends Controller
{
    public function __construct(
        private readonly ListWeaningDeviationsUseCase $list,
        private readonly ReviewWeaningDeviationsUseCase $review,
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $kind = $request->query('kind');
        $orderCode = $request->query('order_code');
        $from = $request->query('from');
        $to = $request->query('to');

        $deviations = ($this->list)(
            (int) $this->companyContext->getCompanyId(),
            $status === 'reviewed' ? true : ($status === 'pending' ? false : null),
            is_string($kind) ? WeaningDeviationKind::tryFrom(strtoupper($kind)) : null,
            is_string($orderCode) && $orderCode !== '' ? $orderCode : null,
            is_string($from) && $from !== '' ? substr($from, 0, 10) : null,
            is_string($to) && $to !== '' ? substr($to, 0, 10) : null
        );

        return response()->json(array_map(fn ($d) => (new WeaningDeviationResource($d))->resolve($request), $deviations));
    }

    public function review(ReviewWeaningDeviationsRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $reviewed = ($this->review)(
                array_map('intval', $validated['ids']),
                (int) $this->companyContext->getCompanyId(),
                $request->user()?->id,
                (string) $validated['note']
            );
        } catch (WeaningOrderDomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getErrorCode()], 422);
        }

        return response()->json(array_map(fn ($d) => (new WeaningDeviationResource($d))->resolve($request), $reviewed));
    }
}
