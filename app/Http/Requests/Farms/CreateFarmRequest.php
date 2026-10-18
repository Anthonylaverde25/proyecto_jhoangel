<?php

declare(strict_types=1);

namespace App\Http\Requests\Farms;

use App\Core\Interfaces\ICompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateFarmRequest extends FormRequest
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
        // A farm hangs from a provider of the same company; its RENSPA is unique inside the company.
        $companyId = app(ICompanyContext::class)->getCompanyId();

        return [
            'name'        => 'required|string|max:255',
            'renspa'      => ['nullable', 'string', 'max:255', Rule::unique('farms', 'renspa')->where('company_id', $companyId)],
            'location'    => 'nullable|string|max:500',
            'provider_id' => ['required', 'integer', Rule::exists('providers', 'id')->where('company_id', $companyId)],
        ];
    }
}
