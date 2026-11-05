<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Core\Enums\CastrationMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterCastrationRequest extends FormRequest
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
            'castrated_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['nullable', Rule::enum(CastrationMethod::class)],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.caravan_id' => ['required', 'integer', 'distinct'],
            'items.*.target_category_code' => ['nullable', 'string', Rule::in(['NOVILLITO', 'NOVILLO'])],
            'items.*.target_category_id' => ['nullable', 'integer', 'exists:animal_categories,id'],
        ];
    }
}

