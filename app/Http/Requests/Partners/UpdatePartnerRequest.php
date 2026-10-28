<?php

declare(strict_types=1);

namespace App\Http\Requests\Partners;

use App\Http\Requests\Concerns\ScopesToActiveCompany;
use App\Http\Rules\ValidCuit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Identity of a partner. Roles and contacts have their own endpoints; farms are not edited here.
 */
class UpdatePartnerRequest extends FormRequest
{
    use ScopesToActiveCompany;

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
        return [
            'name'            => 'required|string|max:255',
            'commercial_name' => 'nullable|string|max:255',
            'cuit'            => ['bail', 'required', 'string', new ValidCuit(), Rule::unique('partners', 'cuit')->where('company_id', $this->companyId())->ignore($this->partnerId())],
            'location'        => 'nullable|string|max:500',
        ];
    }

    private function partnerId(): int
    {
        return (int) ($this->route('id') ?? $this->route('partner') ?? $this->route('provider'));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cuit.unique' => 'Ya hay un cliente o proveedor con ese CUIT en esta empresa.',
        ];
    }
}
