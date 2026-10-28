<?php

declare(strict_types=1);

namespace App\Http\Requests\ExitOrders;

use Illuminate\Foundation\Http\FormRequest;

/** One load of an issued order: date, rows and the DTE when in hand. */
class ExecuteExitOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ExitLinesRules::rules();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ExitLinesRules::messages();
    }
}
