<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Stock\FiscalDocumentDTO;
use App\Application\UseCases\FiscalDocuments\FiscalDocumentUseCases;
use App\Core\Entities\FiscalDocumentEntity;
use App\Core\ValueObjects\FiscalDocumentFilters;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\ReasonRequest;
use App\Http\Requests\Stock\StoreFiscalDocumentRequest;
use App\Http\Resources\FiscalDocumentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Register of provider documents (DS14). No update or delete: a mistake is voided and loaded again.
 */
class FiscalDocumentController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(
        private readonly FiscalDocumentUseCases $documents
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = FiscalDocumentFilters::fromArray($request->query());
        $page = ($this->documents->search)($filters, $this->activeCompanyId());

        return response()->json([
            'data' => array_map(fn (FiscalDocumentEntity $d) => (new FiscalDocumentResource($d))->resolve($request), $page['data']),
            'total' => $page['total'],
            'page' => $filters->page,
            'per_page' => $filters->perPage,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $document = ($this->documents->find)($id, $this->activeCompanyId());

        return response()->json((new FiscalDocumentResource($document))->detailed()->resolve($request));
    }

    public function store(StoreFiscalDocumentRequest $request): JsonResponse
    {
        $registration = ($this->documents->register)(
            FiscalDocumentDTO::fromArray($request->validated(), $request->user()?->id),
            $this->activeCompanyId(),
        );

        return response()->json(
            (new FiscalDocumentResource($registration->document))->detailed($registration->incidents)->resolve($request),
            $registration->created ? 201 : 200,
        );
    }

    public function void(ReasonRequest $request, int $id): JsonResponse
    {
        $document = ($this->documents->void)($id, (string) $request->validated('reason'), $this->activeCompanyId(), $request->user()?->id);

        return response()->json((new FiscalDocumentResource($document))->detailed()->resolve($request));
    }
}
