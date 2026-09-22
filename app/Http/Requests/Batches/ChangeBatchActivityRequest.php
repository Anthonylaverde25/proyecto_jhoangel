<?php

declare(strict_types=1);

namespace App\Http\Requests\Batches;

use App\Models\Batch;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correction of a mis-classified batch, NOT a livestock movement: moving animals
 * between production stages is done by transferring caravans to another batch.
 */
class ChangeBatchActivityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'activity_id' => 'required|integer|exists:activities,id',
            'weight'      => 'nullable|numeric|min:0',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $activityId = $this->input('activity_id');
            $batchId = $this->route('id');

            if (!$activityId || !$batchId) {
                return;
            }

            $batch = Batch::with('batchType')->find($batchId);
            $batchType = $batch?->batchType;

            // A cross-cutting type (activity_id === null) fits any activity.
            if ($batchType && $batchType->activity_id !== null
                && (int) $batchType->activity_id !== (int) $activityId) {
                $validator->errors()->add(
                    'activity_id',
                    'La actividad seleccionada no corresponde al tipo de lote (' . $batchType->name . ').'
                );
            }
        });
    }
}
