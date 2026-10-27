<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Paternity\ConfirmPaternityDTO;
use App\Application\UseCases\Paternity\ConfirmPaternitiesUseCase;
use App\Application\UseCases\Paternity\GetPendingPaternitiesUseCase;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Paternity\ConfirmPaternitiesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Paternidad pendiente": calves born without a confirmed sire, and the confirmation of it.
 */
class PaternityController extends Controller
{
    public function __construct(
        private readonly ICompanyContext $companyContext,
    ) {
    }

    public function pending(Request $request, GetPendingPaternitiesUseCase $useCase): JsonResponse
    {
        $serviceOrderId = $request->integer('service_order_id') ?: null;

        return response()->json($useCase($this->companyId(), $serviceOrderId, $request->string('search')->toString() ?: null));
    }

    public function confirm(ConfirmPaternitiesRequest $request, ConfirmPaternitiesUseCase $useCase): JsonResponse
    {
        $confirmations = array_map(
            static fn (array $row): ConfirmPaternityDTO => ConfirmPaternityDTO::fromArray($row),
            $request->validated('confirmations')
        );

        return response()->json($useCase(array_values($confirmations), $this->companyId()));
    }

    private function companyId(): int
    {
        return (int) $this->companyContext->getCompanyId();
    }
}
