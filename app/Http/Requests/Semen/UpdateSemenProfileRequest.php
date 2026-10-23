<?php

declare(strict_types=1);

namespace App\Http\Requests\Semen;

use App\Core\Enums\SemenPreservation;
use App\Core\Enums\StrawColor;
use App\Core\Enums\StrawSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSemenProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'preservation' => ['required', Rule::enum(SemenPreservation::class)],
            'straw_size' => ['nullable', Rule::enum(StrawSize::class)],
            'straw_color' => ['nullable', Rule::enum(StrawColor::class)],
            'cap_color' => ['nullable', Rule::enum(StrawColor::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'preservation.required' => 'Elegí la conservación.',
            'preservation.enum' => 'Elegí la conservación.',
            'straw_size.enum' => 'Elegí un tamaño de la lista.',
            'straw_color.enum' => 'Elegí un color de la lista.',
            'cap_color.enum' => 'Elegí un color de la lista.',
        ];
    }
}
