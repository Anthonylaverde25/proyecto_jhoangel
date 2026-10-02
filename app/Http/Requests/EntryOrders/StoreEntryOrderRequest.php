<?php

declare(strict_types=1);

namespace App\Http\Requests\EntryOrders;

use App\Core\Enums\BatchNameMode;
use App\Core\Enums\SexComposition;
use App\Core\Enums\TroopCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The troop of an entry order as the "Alta de Lote Externo" form sends it. Shape only: the rules
 * between fields live in EntryTroop and the ones against the catalogues in EntryOrderValidator, so
 * the paper and the screen are held to the same rules.
 */
final class StoreEntryOrderRequest extends FormRequest
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
        return [
            'confirm' => 'sometimes|boolean',
            'provider_id' => 'required|integer|exists:providers,id',
            'farm_id' => 'required|integer|exists:farms,id',
            'auction_number' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'batch_name_mode' => ['required', Rule::enum(BatchNameMode::class)],
            'batch_name' => 'nullable|required_if:batch_name_mode,CUSTOM|string|max:255',
            'head_count' => 'required|integer|min:1|max:100000',
            'category_id' => 'required|integer|exists:animal_categories,id',
            'sex_composition' => ['required', Rule::enum(SexComposition::class)],
            'male_count' => 'nullable|integer|min:0',
            'female_count' => 'nullable|integer|min:0',
            'condition' => ['required', Rule::enum(TroopCondition::class)],
            'age_min_months' => 'nullable|integer|min:0|max:255',
            'age_max_months' => 'nullable|integer|min:0|max:255',
            'knows_to_eat' => 'required|boolean',
            'tick_vaccinated' => 'required|boolean',
            'shrink_percent' => 'nullable|numeric|min:0|max:99.99',
            'estimated_weight' => 'required|numeric|gt:0|max:2000',
            'min_weight' => 'nullable|numeric|gt:0|max:2000',
            'max_weight' => 'nullable|numeric|gt:0|max:2000',
            'purchase_date' => 'required|date|before_or_equal:today',
            'responsable' => 'nullable|string|max:255',
            'observations' => 'nullable|string|max:2000',
            'breeds' => 'required|array|min:1|max:10',
            'breeds.*.breed_id' => 'required|integer|exists:breeds,id',
            'breeds.*.color_id' => 'nullable|integer|exists:colors,id',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider_id.required' => 'Elegí el proveedor.',
            'farm_id.required' => 'Elegí el establecimiento de origen.',
            'auction_number.regex' => 'La terminación de subasta lleva sólo letras y números.',
            'batch_name.required_if' => 'Escribí el nombre del lote.',
            'head_count.required' => 'Indicá cuántas cabezas se compraron.',
            'head_count.min' => 'La orden tiene que tener al menos una cabeza.',
            'category_id.required' => 'Elegí la categoría.',
            'sex_composition.required' => 'Indicá si la tropa es de machos, hembras o ambos.',
            'condition.required' => 'Indicá el estado de la tropa.',
            'knows_to_eat.required' => 'Indicá si la tropa sabe comer.',
            'tick_vaccinated.required' => 'Indicá si la tropa está vacunada contra la garrapata.',
            'shrink_percent.max' => 'El desbaste es un porcentaje menor a 100.',
            'estimated_weight.required' => 'Indicá el peso aproximado.',
            'estimated_weight.gt' => 'El peso aproximado tiene que ser mayor a cero.',
            'purchase_date.required' => 'Indicá la fecha de compra.',
            'purchase_date.before_or_equal' => 'La fecha de compra no puede ser futura.',
            'breeds.required' => 'Declará al menos una raza.',
            'breeds.min' => 'Declará al menos una raza.',
            'breeds.*.breed_id.required' => 'Elegí la raza de cada renglón.',
        ];
    }
}
