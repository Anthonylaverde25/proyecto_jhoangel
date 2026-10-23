<?php

declare(strict_types=1);

namespace App\Http\Requests\Inseminations;

use App\Core\Enums\NotInseminatedReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EndInseminationServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'end_date' => 'required|date_format:Y-m-d',
            'observations' => 'nullable|string|max:1000',
            'not_inseminated_reasons' => 'nullable|array',
            'not_inseminated_reasons.*.female_caravan_id' => 'required|integer',
            'not_inseminated_reasons.*.reason' => ['nullable', Rule::enum(NotInseminatedReason::class)],
        ];
    }
}
