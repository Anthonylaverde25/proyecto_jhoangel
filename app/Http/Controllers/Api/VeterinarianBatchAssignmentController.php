<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Models\VeterinarianBatchAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ADR-7: binds a professional to the troop they may see in the portal. Without an assignment
 * the portal shows nothing, which is the intended fail-closed default.
 */
class VeterinarianBatchAssignmentController extends Controller
{
    public function index(Request $request, ICompanyContext $companyContext): JsonResponse
    {
        $query = VeterinarianBatchAssignment::query()
            ->with(['veterinarian', 'batch'])
            ->where('company_id', $companyContext->getCompanyId());

        if ($request->filled('veterinarian_id')) {
            $query->where('veterinarian_id', (int) $request->query('veterinarian_id'));
        }

        if ($request->boolean('active_only')) {
            $query->whereNull('unassigned_at');
        }

        $assignments = $query->orderByDesc('assigned_at')->get()->map(static fn ($row): array => [
            'id' => (int) $row->id,
            'veterinarian_id' => (int) $row->veterinarian_id,
            'veterinarian_name' => $row->veterinarian?->name,
            'batch_id' => (int) $row->batch_id,
            'batch_name' => $row->batch?->name,
            'assigned_at' => $row->assigned_at?->format('Y-m-d'),
            'unassigned_at' => $row->unassigned_at?->format('Y-m-d'),
            'is_active' => $row->unassigned_at === null,
        ]);

        return response()->json(['data' => $assignments]);
    }

    public function store(Request $request, ICompanyContext $companyContext): JsonResponse
    {
        $validated = $request->validate([
            'veterinarian_id' => ['required', 'integer', 'exists:veterinarians,id'],
            'batch_id' => ['required', 'integer', 'exists:batches,id'],
            'assigned_at' => ['nullable', 'date'],
        ]);

        $companyId = (int) $companyContext->getCompanyId();
        $assignedAt = $validated['assigned_at'] ?? Carbon::now()->toDateString();

        // Re-assigning an already active pair is a no-op rather than a duplicate row.
        $assignment = VeterinarianBatchAssignment::firstOrCreate(
            [
                'company_id' => $companyId,
                'veterinarian_id' => (int) $validated['veterinarian_id'],
                'batch_id' => (int) $validated['batch_id'],
                'assigned_at' => $assignedAt,
            ],
            [
                'assigned_by_user_id' => $request->user()?->getAuthIdentifier(),
            ]
        );

        return response()->json(['data' => ['id' => (int) $assignment->id]], 201);
    }

    public function destroy(int $id, ICompanyContext $companyContext): JsonResponse
    {
        // Closed, not deleted: the historical record of who could see what must survive.
        $updated = VeterinarianBatchAssignment::query()
            ->where('id', $id)
            ->where('company_id', $companyContext->getCompanyId())
            ->whereNull('unassigned_at')
            ->update(['unassigned_at' => Carbon::now()->toDateString()]);

        return response()->json([
            'success' => $updated > 0,
            'message' => $updated > 0 ? 'Asignación finalizada.' : 'La asignación no existe o ya estaba finalizada.',
        ], $updated > 0 ? 200 : 404);
    }
}
