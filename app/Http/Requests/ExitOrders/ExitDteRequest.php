<?php

declare(strict_types=1);

namespace App\Http\Requests\ExitOrders;

use Illuminate\Foundation\Http\FormRequest;

/** A DTE loaded on an exit, at any time but on a cancelled order. */
class ExitDteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dte_number' => ['required', 'string', 'max:40'],
            'dte_date' => ['required', 'date_format:Y-m-d'],
            'head_count' => ['required', 'integer', 'min:1'],
            'observations' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
