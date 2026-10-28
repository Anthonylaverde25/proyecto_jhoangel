<?php

declare(strict_types=1);

namespace App\Http\Requests\Providers;

use App\Core\Interfaces\ICompanyContext;
use App\Http\Rules\ValidCuit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Identity and contact of a provider. Roles have their own endpoint and farms are not edited here.
 */
class UpdateProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The CUIT is compared and stored as digits, so "30-12345678-1" and "30123456781" collide.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('cuit'))) {
            $this->merge(['cuit' => preg_replace('/\D/', '', $this->input('cuit'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(ICompanyContext::class)->getCompanyId();
        $providerId = (int) $this->route('provider');

        return [
            'name'            => 'required|string|max:255',
            'commercial_name' => 'nullable|string|max:255',
            'cuit'            => ['bail', 'required', 'string', new ValidCuit(), Rule::unique('partners', 'cuit')->where('company_id', $companyId)->ignore($providerId)],
            'location'        => 'nullable|string|max:500',
            'email'           => ['nullable', 'email', 'max:255'],
            // A contact needs a phone (C6): an email alone has nowhere to go.
            'phone'           => 'nullable|required_with:email|string|max:50',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cuit.unique' => 'Ya hay un cliente o proveedor con ese CUIT en esta empresa.',
        ];
    }
}
