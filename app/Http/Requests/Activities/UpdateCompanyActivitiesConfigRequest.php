<?php

declare(strict_types=1);

namespace App\Http\Requests\Activities;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyActivitiesConfigRequest extends FormRequest
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
            'activities' => 'required|array|min:1',
            'activities.*.activity_id' => 'required|integer|exists:activities,id',
            'activities.*.is_enabled' => 'required|boolean',
            'activities.*.is_initial' => 'required|boolean',
            'activities.*.is_final' => 'required|boolean',
            'activities.*.sort_order' => 'required|integer|min:1',
        ];
    }
}
