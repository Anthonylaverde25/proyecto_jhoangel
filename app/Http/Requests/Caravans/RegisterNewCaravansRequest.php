<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use App\Core\Enums\AnimalSex;
use App\Core\Interfaces\ICompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Registration of new animals read by an electronic reader at the chute.
 *
 * The identification is the ISO 11784 electronic number: 3 digits of country or
 * manufacturer code followed by 12 digits of national id, with no separators.
 */
class RegisterNewCaravansRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = app(ICompanyContext::class)->getCompanyId();

        return [
            'submission_id' => 'required|uuid',
            'caravans' => 'required|array|min:1|max:1000',
            'caravans.*.identification' => ['required', 'string', 'regex:/^\d{15}$/', 'distinct'],
            'caravans.*.sex' => ['required', new Enum(AnimalSex::class)],
            'caravans.*.teeth' => 'required|integer|min:0|max:99',
            'caravans.*.batch_id' => [
                'required',
                'integer',
                Rule::exists('batches', 'id')->where('company_id', $companyId),
            ],
            'caravans.*.category_id' => 'nullable|integer|exists:animal_categories,id',
            'caravans.*.subcategory_id' => 'nullable|integer|exists:animal_subcategories,id',
            'caravans.*.breed_id' => 'nullable|integer|exists:breeds,id',
            'caravans.*.entry_date' => 'nullable|date',
            'caravans.*.entry_weight' => 'nullable|numeric|min:0',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'caravans.*.identification.regex' => 'La caravana :input no es un número electrónico válido de 15 dígitos.',
            'caravans.*.identification.distinct' => 'La caravana :input está repetida en el envío.',
        ];
    }
}
