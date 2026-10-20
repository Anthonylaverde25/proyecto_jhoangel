<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Company\UpdateCompanyFiscalDataUseCase;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateCompanyFiscalDataRequest;
use App\Http\Resources\CompanyResource;
use Illuminate\Http\JsonResponse;

class CompanyController extends Controller
{
    public function __construct(
        private readonly UpdateCompanyFiscalDataUseCase $updateFiscalData,
    ) {
    }

    /**
     * Carga o borra el CUIT de la empresa activa.
     */
    public function updateFiscalData(UpdateCompanyFiscalDataRequest $request): JsonResponse
    {
        $entity = ($this->updateFiscalData)(
            (int) app(ICompanyContext::class)->getCompanyId(),
            $request->validated('cuit')
        );

        return response()->json(new CompanyResource($entity));
    }
}
