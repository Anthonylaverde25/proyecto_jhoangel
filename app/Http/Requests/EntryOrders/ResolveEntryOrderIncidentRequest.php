<?php

declare(strict_types=1);

namespace App\Http\Requests\EntryOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What was agreed with the provider. Whether it is blank is checked by the entity, so the error
 * carries the domain code.
 */
final class ResolveEntryOrderIncidentRequest extends FormRequest
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
            'resolution' => 'nullable|string|max:2000',
        ];
    }
}
