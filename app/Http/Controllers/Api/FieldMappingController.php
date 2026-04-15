<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\FieldMappings\FieldMappingUseCases;
use App\Http\Controllers\Controller;
use App\Models\FieldMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FieldMappingController extends Controller
{
    public function __construct(
        private readonly FieldMappingUseCases $fieldMappings
    ) {
    }
    /**
     * List all field mappings for a given target model.
     *
     * @param string $model
     * @return JsonResponse
     */
    public function index(string $model): JsonResponse
    {
        $mappings = FieldMapping::forModel($model)
            ->orderBy('target_field')
            ->get(['id', 'alias_name', 'target_field', 'target_model']);

        return response()->json([
            'status' => 'success',
            'model'  => $model,
            'count'  => $mappings->count(),
            'data'   => $mappings,
        ]);
    }

    /**
     * Learn a new field alias from manual user assignment.
     *
     * @param Request                  $request
     * @param LearnFieldMappingUseCase $learnUseCase
     * @return JsonResponse
     */
    public function learn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'alias_name'   => 'required|string|max:255',
            'target_field' => 'required|string|max:255',
            'target_model' => 'required|string|max:255',
        ]);

        ($this->fieldMappings->learn)(
            $validated['alias_name'],
            $validated['target_field'],
            $validated['target_model']
        );

        return response()->json([
            'status'  => 'success',
            'message' => "Alias '{$validated['alias_name']}' mapped to '{$validated['target_field']}' for model '{$validated['target_model']}'.",
        ], 201);
    }
}
