<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use App\Http\Requests\Concerns\ValidatesBatchClassification;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkTransferCaravansRequest extends FormRequest
{
    use ValidatesBatchClassification;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->header('X-Company-ID');

        return [
            'caravan_ids'     => 'required|array|min:1',
            'caravan_ids.*'   => 'required|integer|exists:caravans,id',
            'target_batch_id' => 'nullable|integer|exists:batches,id',
            'reason'          => 'nullable|string|max:500',
            'movement_date'   => 'nullable|date',

            // Destination batch created in the same transaction as the transfer.
            'new_batch'                 => 'nullable|array',
            'new_batch.name'            => 'required_with:new_batch|string|max:255',
            'new_batch.activity_id'     => 'nullable|integer|exists:activities,id',
            'new_batch.farm_id'         => 'nullable|integer|exists:farms,id',
            'new_batch.is_confined'     => 'nullable|boolean',
            'new_batch.batch_type_id'   => [
                'required_with:new_batch',
                'integer',
                Rule::exists('company_batch_type', 'batch_type_id')
                    ->where('company_id', $companyId)
                    ->where('is_enabled', true),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $newBatch = $this->input('new_batch');

            if (!is_array($newBatch) || $newBatch === []) {
                return;
            }

            // The two destinations are mutually exclusive: either an existing batch or a new one.
            if ($this->filled('target_batch_id')) {
                $validator->errors()->add(
                    'new_batch',
                    'Elegí un lote de destino existente o creá uno nuevo, no ambos.'
                );
            }

            $this->validateBatchClassification(
                $validator,
                $newBatch['activity_id'] ?? null,
                $newBatch['batch_type_id'] ?? null,
                array_key_exists('is_confined', $newBatch),
                'new_batch.batch_type_id',
                'new_batch.is_confined'
            );
        });
    }
}
