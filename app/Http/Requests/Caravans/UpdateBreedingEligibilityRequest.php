<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use App\Core\Enums\BreedingExclusionReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBreedingEligibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'caravan_ids'   => 'required|array|min:1|max:500',
            'caravan_ids.*' => 'required|integer|distinct',
            'is_eligible'   => 'required|boolean',
            'reason'        => ['nullable', 'string', Rule::enum(BreedingExclusionReason::class)],
            'note'          => 'nullable|string|max:255',
        ];
    }
}
