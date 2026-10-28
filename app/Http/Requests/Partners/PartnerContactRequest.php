<?php

declare(strict_types=1);

namespace App\Http\Requests\Partners;

use Illuminate\Foundation\Http\FormRequest;

/** Adds or corrects one contact person of a partner (C6). */
class PartnerContactRequest extends FormRequest
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
        return PartnerContactRules::for();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return PartnerContactRules::messages();
    }
}
