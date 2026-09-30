<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\RegisterCaravanDTO;
use App\Application\UseCases\Caravans\RegisterNewCaravansUseCase;
use App\Core\Enums\AnimalSex;
use App\Core\Exceptions\CaravansAlreadyRegisteredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Caravans\RegisterNewCaravansRequest;
use Illuminate\Http\JsonResponse;

class RegisterNewCaravansController extends Controller
{
    public function __construct(
        private readonly RegisterNewCaravansUseCase $registerNewCaravans,
    ) {
    }

    /**
     * Strict, all-or-nothing registration of new animals read at the chute.
     *
     * A retried submission returns the original response instead of a conflict on its own
     * tags, so the phone can resend safely after losing the reply.
     */
    public function __invoke(RegisterNewCaravansRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dtos = array_map(static fn (array $row): RegisterCaravanDTO => new RegisterCaravanDTO(
            identification: $row['identification'],
            sex: AnimalSex::from($row['sex']),
            teeth: (int) $row['teeth'],
            entryWeight: isset($row['entry_weight']) ? (float) $row['entry_weight'] : null,
            breedId: isset($row['breed_id']) ? (int) $row['breed_id'] : null,
            batchId: (int) $row['batch_id'],
            categoryId: isset($row['category_id']) ? (int) $row['category_id'] : null,
            subcategoryId: isset($row['subcategory_id']) ? (int) $row['subcategory_id'] : null,
            entryDate: $row['entry_date'] ?? null,
        ), $validated['caravans']);

        try {
            $registered = ($this->registerNewCaravans)($validated['submission_id'], $dtos);
        } catch (CaravansAlreadyRegisteredException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'conflicts' => $exception->getConflicts(),
            ], 409);
        }

        return response()->json(['data' => ['registered' => count($registered), 'caravans' => $registered]], 201);
    }
}
