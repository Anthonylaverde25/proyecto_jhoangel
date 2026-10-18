<?php

declare(strict_types=1);

namespace App\Http\Requests\Providers;

use App\Core\Interfaces\ICompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        // Providers and their farms are company-scoped: a CUIT, an email or a RENSPA may repeat
        // across companies, never inside one.
        $companyId = app(ICompanyContext::class)->getCompanyId();

        return [
            'name'            => 'required|string|max:255',
            'commercial_name' => 'nullable|string|max:255',
            'cuit'            => ['required', 'string', 'max:20', Rule::unique('providers', 'cuit')->where('company_id', $companyId)],
            'location'        => 'nullable|string|max:500',
            'email'           => ['nullable', 'email', 'max:255', Rule::unique('providers', 'email')->where('company_id', $companyId)],
            'phone'           => 'nullable|string|max:50',
            'supplies_livestock' => 'sometimes|boolean',
            'supplies_items'  => 'sometimes|boolean',
            'farms'           => 'nullable|array',
            'farms.*.name'    => 'required|string|max:255',
            'farms.*.renspa'  => ['nullable', 'string', 'distinct', 'max:255', Rule::unique('farms', 'renspa')->where('company_id', $companyId)],
            'farms.*.location'=> 'nullable|string|max:500',
        ];
    }
}
