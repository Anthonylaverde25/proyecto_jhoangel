<?php

declare(strict_types=1);

namespace App\Http\Requests\Inseminations;

use Illuminate\Foundation\Http\FormRequest;

class AddRepasoBullsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'male_caravan_ids' => 'required|array|min:1',
            'male_caravan_ids.*' => 'required|integer',
            'joined_at' => 'required|date_format:Y-m-d',
        ];
    }
}
