<?php

declare(strict_types=1);

namespace App\Http\Requests\Providers;

use Illuminate\Foundation\Http\FormRequest;

class CreateProviderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'            => 'required|string|max:255',
            'commercial_name' => 'nullable|string|max:255',
            'cuit'            => 'required|string|max:20|unique:providers,cuit',
            'location'        => 'nullable|string|max:500',
            'email'           => 'nullable|email|max:255',
            'phone'           => 'nullable|string|max:50',
            'farms'           => 'nullable|array',
            'farms.*.name'    => 'required|string|max:255',
            'farms.*.renspa'  => 'required|string|distinct|unique:farms,renspa|max:255',
            'farms.*.location'=> 'nullable|string|max:500',
        ];
    }
}
