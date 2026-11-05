<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceOrders;

use Illuminate\Foundation\Http\FormRequest;

class IssueServiceOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'service_method'                     => 'required|string|in:NATURAL,AI',
            'start_date'                         => 'required|date',
            'end_date'                           => 'nullable|date|after_or_equal:start_date',
            'observations'                       => 'nullable|string|max:1000',
            'lots'                               => 'required|array|min:1',
            'lots.*.batch_id'                    => 'required|integer|distinct',
            'lots.*.male_caravan_ids'            => 'nullable|array',
            'lots.*.male_caravan_ids.*'          => 'integer',
            'lots.*.selected_female_caravan_ids' => 'nullable|array',
            'lots.*.selected_female_caravan_ids.*' => 'integer',
            'lots.*.is_controlled_service'       => 'sometimes|boolean',
            'lots.*.service_type'                => 'sometimes|string|in:single,multi,rotation',
            'lots.*.planned_semen'               => 'nullable|array',
            'lots.*.planned_semen.*.female_caravan_id' => 'required_with:lots.*.planned_semen|integer',
            'lots.*.planned_semen.*.item_id'     => 'nullable|integer',
        ];
    }
}
