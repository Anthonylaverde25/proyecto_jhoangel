<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\DeliveryNotes\DeliveryNoteUseCases;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeliveryNotes\InvoiceDraftRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Remitos a facturar" and "Convertir a factura" (delivery C, DC5 and DC12). */
class DeliveryNoteController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(
        private readonly DeliveryNoteUseCases $deliveryNotes
    ) {
    }

    public function pending(Request $request): JsonResponse
    {
        $providerId = $request->query('provider_id');

        return response()->json([
            'data' => ($this->deliveryNotes->pending)(is_numeric($providerId) ? (int) $providerId : null, $this->activeCompanyId()),
        ]);
    }

    public function invoiceDraft(InvoiceDraftRequest $request): JsonResponse
    {
        $ids = array_map('intval', (array) $request->validated('ids'));

        return response()->json(($this->deliveryNotes->invoiceDraft)($ids, $this->activeCompanyId()));
    }
}
