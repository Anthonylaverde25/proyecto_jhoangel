<?php

declare(strict_types=1);

namespace App\Http\Requests\WeighingOrders;

use App\Core\Enums\BatchWeighingEntry;
use App\Core\Enums\ShrunkCondition;
use App\Core\Enums\WeighingTypeCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterBatchWeighingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $groupTypes = array_values(array_map(
            static fn (WeighingTypeCode $c): string => $c->value,
            array_filter(WeighingTypeCode::cases(), static fn (WeighingTypeCode $c): bool => $c->allowsGroupWeighing())
        ));

        return [
            'batch_id' => ['required', 'integer', 'exists:batches,id'],
            'weighing_type' => ['required', Rule::in($groupTypes)],
            'weighing_date' => ['required', 'date', 'before_or_equal:today'],
            'weighing_time' => ['nullable', 'date_format:H:i'],
            'shrunk_condition' => ['nullable', Rule::enum(ShrunkCondition::class)],
            'shrink_pct' => [
                'nullable', 'numeric', 'gt:0', 'lt:30',
                'required_if:shrunk_condition,' . ShrunkCondition::CALCULATED->value,
                'prohibited_unless:shrunk_condition,' . ShrunkCondition::CALCULATED->value,
            ],
            'responsable' => ['nullable', 'string', 'max:255'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'entry' => ['required', Rule::in([BatchWeighingEntry::LOADS->value, BatchWeighingEntry::BY_CATEGORY->value])],
            'averages' => ['required_if:entry,BY_CATEGORY', 'prohibited_unless:entry,BY_CATEGORY', 'array', 'min:1', 'max:50'],
            'averages.*.category_id' => ['nullable', 'integer', 'exists:animal_categories,id'],
            'averages.*.head_count' => ['required', 'integer', 'min:1', 'max:100000'],
            'averages.*.average_weight' => ['required', 'numeric', 'gt:0', 'max:1200'],
            'groups' => ['required_if:entry,LOADS', 'prohibited_unless:entry,LOADS', 'array', 'min:1', 'max:200'],
            'groups.*.category_id' => ['nullable', 'integer', 'exists:animal_categories,id'],
            'groups.*.head_count' => ['required', 'integer', 'min:1', 'max:200'],
            'groups.*.gross_weight' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'groups.*.tare_weight' => ['nullable', 'numeric', 'min:0', 'max:50000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'weighing_type.in' => 'Ese tipo de pesaje se toma animal por animal, no por lote.',
            'weighing_date.before_or_equal' => 'La fecha del pesaje no puede ser futura.',
            'groups.required_if' => 'Cargá al menos una jaula.',
            'averages.required_if' => 'Cargá el promedio de al menos una categoría.',
            'averages.*.average_weight.required' => 'Indicá el promedio por cabeza.',
            'averages.*.average_weight.gt' => 'El promedio tiene que ser mayor a 0 kg.',
            'averages.*.average_weight.max' => 'Más de 1.200 kg/cab no es posible.',
            'groups.*.head_count.min' => 'Cada jaula tiene que tener al menos una cabeza.',
            'groups.*.gross_weight.gt' => 'El bruto tiene que ser mayor a 0 kg.',
        ];
    }
}
