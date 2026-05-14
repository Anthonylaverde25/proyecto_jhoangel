<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\WorkTemplates\WorkTemplateUseCases;
use App\Http\Controllers\Controller;
use App\Http\Resources\TemplateTypeResource;
use App\Http\Resources\WorkTemplateResource;
use App\Core\Interfaces\ICompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkTemplateController extends Controller
{
    public function __construct(
        private readonly WorkTemplateUseCases $useCases,
        private readonly ICompanyContext $companyContext
    ) {
    }

    /**
     * Lista los tipos de plantilla disponibles para la empresa.
     */
    public function types(): JsonResponse
    {
        $companyId = $this->companyContext->getCompanyId();
        
        if (!$companyId) {
            return response()->json(['message' => 'No company context found'], 403);
        }

        $entities = ($this->useCases->listTypes)($companyId);
        
        return response()->json(
            TemplateTypeResource::collection($entities)
        );
    }

    /**
     * Lista todas las plantillas de trabajo de la empresa.
     */
    public function index(): JsonResponse
    {
        $companyId = $this->companyContext->getCompanyId();

        if (!$companyId) {
            return response()->json(['message' => 'No company context found'], 403);
        }

        $entities = ($this->useCases->listTemplates)($companyId);
        
        return response()->json(
            WorkTemplateResource::collection($entities)
        );
    }
}
