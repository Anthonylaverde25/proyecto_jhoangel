<?php

declare(strict_types=1);

namespace App\Http\Requests\Providers;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProviderRolesRequest extends FormRequest
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
            'supplies_livestock' => ['required', 'boolean'],
            'supplies_items' => ['required', 'boolean'],
        ];
    }
}
