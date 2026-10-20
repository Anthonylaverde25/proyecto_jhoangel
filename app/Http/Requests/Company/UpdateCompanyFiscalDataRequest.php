<?php

declare(strict_types=1);

namespace App\Http\Requests\Company;

use App\Http\Rules\ValidCuit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The active company's CUIT. Optional, but when given it must close its check digit: it is the
 * control against which the receiver of every provider document is compared.
 */
class UpdateCompanyFiscalDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('cuit'))) {
            $digits = preg_replace('/\D/', '', $this->input('cuit'));
            $this->merge(['cuit' => $digits === '' ? null : $digits]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Not unique: one owner may run several farms (companies) under the same CUIT.
        return [
            'cuit' => ['present', 'nullable', 'string', new ValidCuit()],
        ];
    }
}
