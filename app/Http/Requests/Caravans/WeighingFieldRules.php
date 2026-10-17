<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use App\Core\Enums\ShrunkCondition;
use App\Core\Enums\WeighingMethod;
use App\Core\Enums\WeighingTypeCode;
use Illuminate\Validation\Rule;

/** The optional fields that describe how a weight was taken, shared by every weighing request. */
final class WeighingFieldRules
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(string $prefix = ''): array
    {
        // One caravan cannot be weighed "as a batch": cage re-weighings and averages belong to
        // the batch weighing, which assigns them.
        $individualTypes = array_values(array_map(
            static fn (WeighingTypeCode $c): string => $c->value,
            array_filter(WeighingTypeCode::cases(), static fn (WeighingTypeCode $c): bool => $c->allowsIndividualWeighing())
        ));

        return [
            $prefix . 'weighing_type'    => ['nullable', Rule::in($individualTypes)],
            $prefix . 'method'           => ['nullable', Rule::enum(WeighingMethod::class)->except([WeighingMethod::AVERAGE])],
            $prefix . 'shrunk_condition' => ['nullable', Rule::enum(ShrunkCondition::class)],
            $prefix . 'shrink_pct'       => [
                'nullable', 'numeric', 'gt:0', 'lt:30',
                'required_if:' . $prefix . 'shrunk_condition,' . ShrunkCondition::CALCULATED->value,
            ],
            $prefix . 'weighing_time'    => ['nullable', 'date_format:H:i'],
        ];
    }
}
