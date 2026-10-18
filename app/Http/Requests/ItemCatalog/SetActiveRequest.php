<?php

declare(strict_types=1);

namespace App\Http\Requests\ItemCatalog;

use Illuminate\Foundation\Http\FormRequest;

class SetActiveRequest extends FormRequest
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
        return ['is_active' => ['required', 'boolean']];
    }
}
