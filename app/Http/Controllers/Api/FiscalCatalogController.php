<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\FiscalDocuments\FiscalCatalogUseCases;
use App\Core\Entities\FiscalDocumentTypeEntity;
use App\Core\Entities\TaxEntity;
use App\Http\Controllers\Controller;
use App\Http\Resources\FiscalCatalogResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only fixed fiscal catalog (DS15): there are no write routes on purpose.
 */
class FiscalCatalogController extends Controller
{
    public function __construct(
        private readonly FiscalCatalogUseCases $catalog
    ) {
    }

    public function documentTypes(): JsonResponse
    {
        return response()->json(array_map(
            fn (FiscalDocumentTypeEntity $type) => FiscalCatalogResource::type($type),
            ($this->catalog->documentTypes)()
        ));
    }

    public function taxes(Request $request): JsonResponse
    {
        $date = $request->query('date');
        $date = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : now()->toDateString();

        return response()->json(array_map(
            fn (TaxEntity $tax) => FiscalCatalogResource::tax($tax),
            ($this->catalog->taxes)($date)
        ));
    }
}
