<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\RegisterCaravanDTO;
use App\Application\UseCases\UpsertCaravanUseCase;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaravanController extends Controller
{
    public function __construct(
        private readonly UpsertCaravanUseCase $upsertUseCase
    ) {
    }

    /**
     * Realiza un Upsert de una caravana.
     * Si la identificación existe, actualiza. Si no, crea.
     */
    public function upsert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identification' => 'required|string',
            'category'       => 'nullable|string',
            'teeth'          => 'required|integer|min:0|max:99',
            'entry_weight'   => 'nullable|numeric',
            'breed'          => 'nullable|string',
            'sex'            => 'nullable|string',
            'entry_date'     => 'nullable|date_format:Y-m-d',
        ]);

        $dto = new RegisterCaravanDTO(
            identification: $validated['identification'],
            category: $validated['category'] ?? null,
            teeth: (int) $validated['teeth'],
            entryWeight: isset($validated['entry_weight']) ? (float) $validated['entry_weight'] : null,
            breed: $validated['breed'] ?? null,
            sex: $validated['sex'] ?? null,
            entryDate: $validated['entry_date'] ?? null
        );

        $result = ($this->upsertUseCase)($dto);

        return response()->json([
            'action' => $result->action,
            'id'     => $result->id,
        ], $result->action === 'created' ? 201 : 200);
    }
}
