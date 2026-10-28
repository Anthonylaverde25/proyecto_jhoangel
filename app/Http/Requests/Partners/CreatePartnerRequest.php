<?php

declare(strict_types=1);

namespace App\Http\Requests\Partners;

use App\Core\Enums\PartnerRole;
use App\Http\Requests\Concerns\ScopesToActiveCompany;
use App\Http\Rules\ValidCuit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePartnerRequest extends FormRequest
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
        // Partners and their farms are company-scoped: a CUIT or a RENSPA may repeat across
        // companies, never inside one.
        $companyId = $this->companyId();

        return [
            'name'            => 'required|string|max:255',
            'commercial_name' => 'nullable|string|max:255',
            'cuit'            => ['bail', 'required', 'string', new ValidCuit(), Rule::unique('partners', 'cuit')->where('company_id', $companyId)],
            'location'        => 'nullable|string|max:500',
            'roles'           => ['sometimes', 'array', 'min:1'],
            'roles.*'         => ['distinct', Rule::enum(PartnerRole::class)],
            'farms'           => 'nullable|array',
            'farms.*.name'    => 'required|string|max:255',
            'farms.*.renspa'  => ['nullable', 'string', 'distinct', 'max:255', Rule::unique('farms', 'renspa')->where('company_id', $companyId)],
            'farms.*.location'=> 'nullable|string|max:500',
            'contact'         => ['nullable', 'array'],
        ] + PartnerContactRules::for('contact', required: false);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'roles.min' => 'Marcá al menos un rol: qué nos vende o qué nos compra.',
            'cuit.unique' => 'Ya hay un cliente o proveedor con ese CUIT en esta empresa.',
        ] + PartnerContactRules::messages('contact');
    }
}
