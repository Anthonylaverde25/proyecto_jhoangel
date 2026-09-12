<?php

declare(strict_types=1);

namespace App\Http\Requests\Veterinary;

use App\Core\Enums\SampleDestinationPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-40: Update the destination plan of an unsigned extraction act.
 *
 * An intention declared by the professional at the chute or in the inbox before
 * freezing the act with a signature.
 */
class UpdateExtractionActDestinationPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'destination_plan' => ['required', Rule::enum(SampleDestinationPlan::class)],
        ];
    }
}
